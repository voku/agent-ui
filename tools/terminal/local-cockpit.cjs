#!/usr/bin/env node
'use strict';

// Experimental, local-only transport for one explicitly started coding host.
// Workflow progress and permissions remain with agent-loop and the host.
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');

const usage = 'Usage: node tools/terminal/local-cockpit.cjs --root=PATH --task=ID (--serve | --start=shell|codex|claude | --attach | --status | --stop) [--port=8090] [--format=json|text]';
const fail = (message) => { console.error(message); process.exit(1); };
const options = {};
for (const argument of process.argv.slice(2)) {
    if (['--attach', '--serve', '--status', '--stop'].includes(argument) && !options[argument.slice(2)]) { options[argument.slice(2)] = true; continue; }
    const match = /^--(root|task|start|port|format)=(.+)$/.exec(argument);
    if (!match || Object.hasOwn(options, match[1])) fail(usage);
    options[match[1]] = match[2];
}
if (!options.root || !/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/.test(options.task || '') ||
    [options.attach, options.start, options.serve, options.status, options.stop].filter(Boolean).length !== 1 ||
    (options.start && !['shell', 'codex', 'claude'].includes(options.start))) fail(usage);
if (options.format && (!options.status || !['text', 'json'].includes(options.format))) fail(usage);
const port = Number(options.port || 8090);
if (!Number.isInteger(port) || port < 1024 || port > 65535) fail('Port must be between 1024 and 65535.');
let root;
try {
    root = fs.realpathSync(options.root);
    if (!fs.statSync(root).isDirectory()) throw new Error('not a directory');
} catch (_) { fail('Project root must resolve to an existing directory.'); }

const packageRoot = path.resolve(__dirname, '../..');
const autoloadCandidates = [process.env.AGENT_UI_COMPOSER_AUTOLOAD, path.join(packageRoot, 'vendor/autoload.php'), path.resolve(packageRoot, '../../autoload.php')];
const autoload = autoloadCandidates.find((candidate) => typeof candidate === 'string' && fs.existsSync(candidate));
const commandOnly = Boolean(options.status || options.stop);
if (!commandOnly && !autoload) fail('Composer autoload was not found; install agent-ui dependencies first.');

// A lock prevents two local transports for the same project/task. A lock left by
// an unclean exit is deliberately not removed automatically; see the README.
const lockId = crypto.createHash('sha256').update(root + '\0' + options.task).digest('hex').slice(0, 24);
const session = 'agent-ui-' + lockId;
if (typeof process.getuid !== 'function') fail('The experimental terminal requires a local POSIX system.');
const lockDir = path.join(fs.realpathSync('/tmp'), `agent-ui-terminal-${process.getuid()}-${lockId}`);
let ownsLock = false;
if (!commandOnly) {
    try { fs.mkdirSync(lockDir, { mode: 0o700 }); } catch (_) { fail(`A terminal transport is active or its lock needs cleanup: ${lockDir}`); }
    ownsLock = true;
    fs.writeFileSync(path.join(lockDir, 'pid'), String(process.pid), { mode: 0o600, flag: 'wx' });
}
const socketPath = path.join(lockDir, 'ttyd.sock');
// Uploaded images must outlive the browser transport: tmux may keep running
// after the launcher exits. Explicit session stop removes the private files.
const attachmentDir = path.join(path.dirname(lockDir), `agent-ui-attachments-${process.getuid()}-${lockId}`);
const maxAttachmentBytes = 8 * 1024 * 1024;
const imageExtensions = Object.freeze({
    'image/png': '.png', 'image/jpeg': '.jpg', 'image/webp': '.webp', 'image/gif': '.gif',
});
function validImage(bytes, type) {
    if (type === 'image/png') return bytes.subarray(0, 8).equals(Buffer.from('89504e470d0a1a0a', 'hex'));
    if (type === 'image/jpeg') return bytes.subarray(0, 3).equals(Buffer.from('ffd8ff', 'hex'));
    if (type === 'image/webp') return bytes.subarray(0, 4).toString() === 'RIFF' && bytes.subarray(8, 12).toString() === 'WEBP';
    if (type === 'image/gif') return ['GIF87a', 'GIF89a'].includes(bytes.subarray(0, 6).toString());
    return false;
}
function validateAttachmentDirectory() {
    // The generated path is typed literally into the terminal. Reject a temp
    // root containing shell metacharacters or whitespace rather than changing
    // how an arbitrary CLI interprets a quoted filename.
    if (!/^\/[A-Za-z0-9._/-]+$/.test(attachmentDir)) throw new Error('The local attachment path cannot be pasted safely.');
    const stat = fs.lstatSync(attachmentDir);
    if (!stat.isDirectory() || stat.isSymbolicLink() || stat.uid !== process.getuid() || (stat.mode & 0o077) !== 0) {
        throw new Error('Private attachment directory has invalid ownership or permissions.');
    }
}
function ensureAttachmentDirectory() {
    try { fs.mkdirSync(attachmentDir, { mode: 0o700 }); }
    catch (error) { if (error.code !== 'EEXIST') throw error; }
    validateAttachmentDirectory();
}
const accessToken = crypto.randomBytes(32).toString('base64url');
const cookieSecret = crypto.randomBytes(32).toString('base64url');
const csrfToken = crypto.randomBytes(32).toString('base64url');
const cookieName = 'agent_ui_terminal_' + crypto.randomBytes(8).toString('hex');
const expectedHost = '127.0.0.1:' + port;
const expectedOrigin = 'http://' + expectedHost;
const children = [];
let server;
let terminalServer;
let terminalHost;
let terminalOrigin;
let terminalAvailable = false;
let quitting = false;
const upgradedSockets = new Set();
let pendingSocket = null;
let attachmentBusy = false;
async function cleanup(exitCode) {
    if (quitting) return;
    quitting = true;
    if (server) { server.close(); server.closeAllConnections(); }
    if (terminalServer) { terminalServer.close(); terminalServer.closeAllConnections(); }
    for (const socket of upgradedSockets) socket.destroy();
    const running = children.filter((item) => typeof item.pid === 'number' && item.exitCode === null && item.signalCode === null);
    const stopped = running.map((item) => new Promise((resolve) => item.once('exit', resolve)));
    for (const item of running) item.kill('SIGTERM');
    await Promise.race([Promise.all(stopped), new Promise((resolve) => setTimeout(resolve, 2000))]);
    for (const item of running) if (item.exitCode === null && item.signalCode === null) item.kill('SIGKILL');
    await Promise.race([Promise.all(stopped), new Promise((resolve) => setTimeout(resolve, 2000))]);
    if (!ownsLock) {
        process.exit(exitCode);
    } else if (running.every((item) => item.exitCode !== null || item.signalCode !== null)) {
        try { fs.rmSync(lockDir, { recursive: true, force: true }); } catch (_) { /* report on next start */ }
    } else {
        console.error(`A transport process did not exit; its lock was retained: ${lockDir}`);
    }
    process.exit(exitCode);
}
process.on('SIGINT', () => cleanup(0));
process.on('SIGTERM', () => cleanup(0));
const launcherPid = Number(process.env.AGENT_UI_SESSION_PARENT_PID || 0);
if (!commandOnly && Number.isSafeInteger(launcherPid) && launcherPid > 0) {
    const watcher = setInterval(() => { if (process.ppid !== launcherPid) cleanup(0); }, 1000);
    watcher.unref();
}
function child(command, args, env, onStderr, onFailure) {
    const processChild = spawn(command, args, { stdio: ['ignore', 'ignore', 'pipe'], env });
    children.push(processChild);
    processChild.stderr.on('data', (data) => { if (onStderr) onStderr(data.toString()); });
    processChild.on('error', () => {
        if (onFailure) onFailure();
        else { console.error(`${command} could not start.`); cleanup(1); }
    });
    processChild.on('exit', () => {
        if (quitting) return;
        if (onFailure) onFailure();
        else { console.error(`${command} exited; the tmux session was left running.`); cleanup(1); }
    });
    return processChild;
}
function equal(a, b) {
    if (typeof a !== 'string' || typeof b !== 'string') return false;
    const left = Buffer.from(a), right = Buffer.from(b);
    return left.length === right.length && crypto.timingSafeEqual(left, right);
}
function authenticated(req) {
    const pairs = (req.headers.cookie || '').split(';').map((part) => part.trim().split('='));
    const matches = pairs.filter((pair) => pair[0] === cookieName);
    return matches.length === 1 && equal(matches[0][1], cookieSecret);
}
function permitted(req, terminal = false) {
    const host = terminal ? terminalHost : expectedHost;
    const origin = terminal ? terminalOrigin : expectedOrigin;
    if (req.headers.host !== host) return false;
    if (req.headers.origin && req.headers.origin !== origin) return false;
    const fetchSite = req.headers['sec-fetch-site'];
    return !fetchSite || fetchSite === 'same-origin' || fetchSite === 'none' || (terminal && fetchSite === 'same-site');
}
function deny(res, code, message) {
    res.writeHead(code, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
    res.end(message);
}
function json(res, code, value) {
    res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
    res.end(JSON.stringify(value));
}
const loginScript = `const token=location.hash.slice(1);history.replaceState(null,'',location.pathname);fetch('/_local/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token})}).then(r=>{if(!r.ok)throw Error();location.replace(${JSON.stringify('/task/' + encodeURIComponent(options.task) + '/progress')})}).catch(()=>{document.body.textContent='Access denied. Start a new local cockpit.'});`;
const loginHash = crypto.createHash('sha256').update(loginScript).digest('base64');
function requestHandler(req, res) {
    if (!permitted(req)) return deny(res, 403, 'Local origin required.');
    if (req.method === 'GET' && req.url === '/_local/access') {
        res.writeHead(200, {
            'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', 'Referrer-Policy': 'no-referrer',
            'Content-Security-Policy': `default-src 'none'; script-src 'sha256-${loginHash}'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`,
            'X-Content-Type-Options': 'nosniff',
        });
        return res.end(`<!doctype html><title>Local cockpit</title><p>Connecting to your local cockpit…</p><script>${loginScript}</script>`);
    }
    if (req.method === 'POST' && req.url === '/_local/login') {
        if (req.headers.origin !== expectedOrigin || req.headers['content-type'] !== 'application/json') return deny(res, 403, 'Origin required.');
        let body = '';
        req.on('data', (chunk) => { body += chunk; if (body.length > 256) req.destroy(); });
        return req.on('end', () => {
            let token;
            try { token = JSON.parse(body).token; } catch (_) { return deny(res, 400, 'Invalid request.'); }
            if (!equal(token, accessToken)) return deny(res, 403, 'Access denied.');
            res.writeHead(204, { 'Set-Cookie': `${cookieName}=${cookieSecret}; HttpOnly; SameSite=Strict; Path=/`, 'Cache-Control': 'no-store' });
            res.end();
        });
    }
    if (!authenticated(req)) return deny(res, 401, 'Open the access URL printed by the local cockpit.');
    if (req.method === 'GET' && req.url === '/_local/session') {
        const state = inspectSession();
        return json(res, 200, { ...state, terminal_available: terminalAvailable, hosts: hostAvailability(), csrf: csrfToken });
    }
    if (req.method === 'POST' && ['/_local/session/start', '/_local/session/stop'].includes(req.url)) {
        if (req.headers.origin !== expectedOrigin || req.headers['content-type'] !== 'application/json' ||
            !equal(req.headers['x-agent-ui-csrf'], csrfToken)) return deny(res, 403, 'Origin and CSRF token required.');
        let body = '';
        req.on('data', (chunk) => { body += chunk; if (body.length > 256) req.destroy(); });
        return req.on('end', async () => {
            let value;
            try { value = JSON.parse(body); } catch (_) { return deny(res, 400, 'Invalid request.'); }
            if (!value || typeof value !== 'object' || Array.isArray(value)) return deny(res, 400, 'Invalid request.');
            try {
                if (req.url === '/_local/session/start') {
                    const requestedHost = value.host === undefined ? 'shell' : value.host;
                    if (!['shell', 'codex', 'claude'].includes(requestedHost)) return deny(res, 400, 'Unsupported terminal host.');
                    if (!terminalAvailable) return deny(res, 503, 'The terminal transport is unavailable.');
                    const taskStatus = await getStatus(`http://127.0.0.1:${phpPort}/task/${encodeURIComponent(options.task)}/progress.json`);
                    if (taskStatus !== 200) return deny(res, 409, 'The task is unavailable in the selected project.');
                    const host = ensureSession(requestedHost, true);
                    return json(res, 200, { state: 'session_running', host });
                }
                const result = stopSession();
                return json(res, 200, { state: 'session_not_found', ...result });
            } catch (error) {
                return deny(res, 409, error.message || 'The session action failed.');
            }
        });
    }
    if (req.method === 'POST' && req.url === '/_local/attachment') {
        return receiveAttachment(req, res);
    }
    if (/^\/_terminal(?:\/|\?|$)/.test(req.url)) return deny(res, 404, 'Not found.');
    proxy(req, res, false);
}
function receiveAttachment(req, res) {
    if (req.headers.origin !== expectedOrigin || !equal(req.headers['x-agent-ui-csrf'], csrfToken)) {
        return deny(res, 403, 'Origin and CSRF token required.');
    }
    if (!terminalAvailable) {
        return deny(res, 409, 'Start an available task session before attaching an image.');
    }
    let target;
    try { target = withSessionLock(sessionTarget); }
    catch (_) { return deny(res, 409, 'The task session is unavailable or changing.'); }
    const type = req.headers['content-type'];
    if (!Object.hasOwn(imageExtensions, type)) return deny(res, 415, 'Only PNG, JPEG, WebP and GIF images are supported.');
    if (Number(req.headers['content-length']) > maxAttachmentBytes) return deny(res, 413, 'Image exceeds 8 MiB.');
    if (attachmentBusy) return deny(res, 409, 'Another image upload is in progress.');
    attachmentBusy = true;
    let length = 0;
    let failed = false;
    const chunks = [];
    req.setTimeout(15000, () => { failed = true; attachmentBusy = false; req.destroy(); });
    req.on('aborted', () => { failed = true; attachmentBusy = false; });
    req.on('error', () => { failed = true; attachmentBusy = false; });
    req.on('data', (chunk) => {
        if (failed) return;
        length += chunk.length;
        if (length > maxAttachmentBytes) {
            failed = true;
            attachmentBusy = false;
            deny(res, 413, 'Image exceeds 8 MiB.');
            req.resume();
            return;
        }
        chunks.push(chunk);
    });
    req.on('end', () => {
        attachmentBusy = false;
        if (failed) return;
        const bytes = Buffer.concat(chunks, length);
        if (!validImage(bytes, type)) return deny(res, 415, 'Image signature does not match its type.');
        let filename;
        try {
            withSessionLock(() => {
                const current = sessionTarget();
                if (current.identity !== target.identity) throw new Error('The task session changed during the upload.');
                ensureAttachmentDirectory();
                if (fs.readdirSync(attachmentDir).length >= 32) throw new Error('Task image limit reached; stop the task session to clean up.');
                filename = path.join(attachmentDir, crypto.randomBytes(16).toString('hex') + imageExtensions[type]);
                fs.writeFileSync(filename, bytes, { flag: 'wx', mode: 0o600 });
                // Target the same pane observed at upload start, under the same
                // task lock used by start/stop; never send Enter.
                const sent = tmuxCommand(['send-keys', '-l', '-t', current.pane, ' ' + filename]);
                if (sent.status !== 0) throw new Error('Unable to paste the image path into the terminal.');
            });
            return json(res, 201, { path: filename });
        } catch (error) {
            if (filename) {
                try { fs.unlinkSync(filename); } catch (_) { /* retain error context */ }
            }
            if (error.message === 'The task session changed during the upload.' || error.message === 'The task session is unavailable.') {
                return deny(res, 409, error.message);
            }
            if (error.message === 'Task image limit reached; stop the task session to clean up.') return deny(res, 409, error.message);
            console.error('Image upload failed:', error.message);
            return deny(res, 503, 'Image could not be saved or pasted into the terminal.');
        }
    });
}
function terminalRequest(req, res) {
    if (!permitted(req, true)) return deny(res, 403, 'Local origin required.');
    if (!authenticated(req)) return deny(res, 401, 'Local terminal authentication required.');
    if (!['GET', 'HEAD'].includes(req.method) || !/^\/_terminal(?:\/|\?|$)/.test(req.url)) return deny(res, 404, 'Not found.');
    proxy(req, res, true);
}
function proxy(req, res, terminal) {
    const headers = { ...req.headers };
    delete headers['proxy-authorization'];
    if (terminal) delete headers.cookie;
    const target = terminal ? { socketPath } : { host: '127.0.0.1', port: phpPort };
    const upstream = http.request({ ...target, path: req.url, method: req.method, headers }, (answer) => {
        const responseHeaders = { ...answer.headers };
        if (terminal) {
            responseHeaders['content-security-policy'] = `frame-ancestors ${expectedOrigin}; base-uri 'none'`;
            delete responseHeaders['x-frame-options'];
            responseHeaders['cache-control'] = 'no-store';
            responseHeaders['referrer-policy'] = 'no-referrer';
        }
        res.writeHead(answer.statusCode || 502, responseHeaders);
        answer.pipe(res);
    });
    upstream.on('error', () => { if (!res.headersSent) deny(res, 503, 'Local service unavailable.'); else res.destroy(); });
    req.pipe(upstream);
}
function rejectUpgrade(socket, code) { socket.end(`HTTP/1.1 ${code} Forbidden\r\nConnection: close\r\n\r\n`); }
function upgrade(req, socket, head) {
    if (!permitted(req, true) || req.headers.origin !== terminalOrigin || !authenticated(req) ||
        req.url !== '/_terminal/ws' || req.headers['sec-websocket-protocol'] !== 'tty' ||
        String(req.headers.upgrade).toLowerCase() !== 'websocket') return rejectUpgrade(socket, 403);
    if (pendingSocket || upgradedSockets.size !== 0) return rejectUpgrade(socket, 409);
    pendingSocket = socket;
    const releasePending = () => { if (pendingSocket === socket) pendingSocket = null; };
    const headers = { ...req.headers };
    delete headers.cookie;
    delete headers['proxy-authorization'];
    const upstream = http.request({ socketPath, path: req.url, method: 'GET', headers });
    socket.once('close', () => { if (!upgradedSockets.has(socket)) { releasePending(); upstream.destroy(); } });
    upstream.on('upgrade', (answer, terminalSocket, terminalHead) => {
        releasePending();
        if (socket.destroyed) { terminalSocket.destroy(); return; }
        upgradedSockets.add(socket);
        socket.once('close', () => upgradedSockets.delete(socket));
        socket.write('HTTP/1.1 101 Switching Protocols\r\n' + answer.rawHeaders.reduce((lines, value, index, all) => index % 2 ? lines : lines + value + ': ' + all[index + 1] + '\r\n', '') + '\r\n');
        if (head.length) terminalSocket.write(head);
        if (terminalHead.length) socket.write(terminalHead);
        socket.on('error', () => terminalSocket.destroy());
        terminalSocket.on('error', () => socket.destroy());
        socket.pipe(terminalSocket).pipe(socket);
    });
    upstream.on('response', (answer) => { releasePending(); answer.resume(); rejectUpgrade(socket, 403); });
    upstream.on('error', () => { releasePending(); rejectUpgrade(socket, 503); });
    upstream.end();
}
let phpPort;
async function waitFor(test, description) {
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await test()) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error(description);
}
function getStatus(url) {
    return new Promise((resolve) => {
        const request = http.get(url, (response) => { response.resume(); resolve(response.statusCode); });
        request.on('error', () => resolve(0));
        request.setTimeout(1000, () => request.destroy());
    });
}
function tmuxCommand(args) {
    const env = { ...process.env, TMUX: '' };
    delete env.TMUX_TMPDIR;
    const result = spawnSync('tmux', ['-L', 'agent-ui', '-f', '/dev/null', ...args], {
        encoding: 'utf8', timeout: 5000, env,
    });
    if (result.error) throw new Error('tmux is unavailable or did not respond.');
    return result;
}
function hostExecutable(name) {
    // POSIX interactive shell is the generic default. Other CLI agents are programs
    // a developer may run inside it; only explicit shortcuts launch a host directly.
    if (name === 'shell') {
        fs.accessSync('/bin/sh', fs.constants.X_OK);
        return '/bin/sh';
    }
    for (const directory of (process.env.PATH || '').split(path.delimiter)) {
        if (!directory) continue;
        try {
            const candidate = fs.realpathSync(path.join(directory, name));
            fs.accessSync(candidate, fs.constants.X_OK);
            if (fs.statSync(candidate).isFile()) return candidate;
        } catch (_) { /* check the next PATH entry */ }
    }
    throw new Error(`The ${name} executable is not available on PATH.`);
}
function hostAvailability() {
    const availability = {};
    for (const name of ['shell', 'codex', 'claude']) {
        try { hostExecutable(name); availability[name] = true; } catch (_) { availability[name] = false; }
    }
    return availability;
}
function inspectSession() {
    const target = '=' + session;
    try {
        const result = tmuxCommand(['has-session', '-t', target]);
        if (result.status === 1) {
            const detail = result.stderr.trim();
            if (/^(no server running on |can't find session: |no such session: )/.test(detail) ||
                /^error connecting to .+ \(No such file or directory\)$/.test(detail)) {
                return { state: 'session_not_found', host: null };
            }
            return { state: 'unavailable', host: null };
        }
        if (result.status !== 0) return { state: 'unavailable', host: null };
        const location = tmuxCommand(['display-message', '-p', '-t', target + ':', '#{session_path}']);
        const identity = tmuxCommand(['show-options', '-v', '-t', target + ':', '@agent-ui-host']);
        const host = identity.stdout.trim();
        if (location.status !== 0 || fs.realpathSync(location.stdout.trim()) !== root ||
            identity.status !== 0 || !['shell', 'codex', 'claude'].includes(host)) return { state: 'unavailable', host: null };
        return { state: 'session_running', host };
    } catch (_) { return { state: 'unavailable', host: null }; }
}
function sessionTarget() {
    if (inspectSession().state !== 'session_running') throw new Error('The task session is unavailable.');
    const result = tmuxCommand(['display-message', '-p', '-t', '=' + session + ':', '#{pid}:#{session_id}:#{pane_id}:#{pane_pid}:#{pane_current_command}']);
    const identity = result.stdout.trim();
    const parts = /^(\d+):(\$\d+):(%\d+):(\d+):([^\n]+)$/.exec(identity);
    if (result.status !== 0 || !parts) throw new Error('The task session is unavailable.');
    return { identity, pane: parts[3] };
}
function withSessionLock(operation) {
    const operationDir = lockDir + '-operation';
    try { fs.mkdirSync(operationDir, { mode: 0o700 }); }
    catch (_) { throw new Error('A task session action is already running; retry shortly.'); }
    try {
        fs.writeFileSync(path.join(operationDir, 'pid'), String(process.pid), { mode: 0o600, flag: 'wx' });
        return operation();
    } finally {
        fs.rmSync(operationDir, { recursive: true, force: true });
    }
}
function ensureSession(requestedHost, create) {
    return withSessionLock(() => {
        const target = '=' + session;
        let state = inspectSession();
        if (state.state === 'session_not_found') {
            if (!create) throw new Error('The task has no running terminal session; start a host explicitly.');
            if (!['shell', 'codex', 'claude'].includes(requestedHost)) throw new Error('Unsupported coding host.');
            const executable = hostExecutable(requestedHost);
            // The command is constant shell syntax; task, root and executable are
            // positional arguments, never interpolated into executable shell code.
            // Export task context for both generic shells and optional direct hosts.
            const launchScript = 'export AGENT_LOOP_TASK_ID="$1" AGENT_UI_PROJECT_ROOT="$2"; exec "$0"' +
                (requestedHost === 'shell' ? ' -i' : '');
            const created = tmuxCommand(['new-session', '-d', '-s', session, '-c', root,
                'sh', '-c', launchScript, executable, options.task, root]);
            if (created.status !== 0) throw new Error('The coding-host session could not be started.');
            const marked = tmuxCommand(['set-option', '-t', target + ':', '@agent-ui-host', requestedHost]);
            if (marked.status !== 0) {
                tmuxCommand(['kill-session', '-t', target]);
                throw new Error('The coding host exited before its session could be recorded.');
            }
            state = inspectSession();
        }
        if (state.state !== 'session_running' || (requestedHost && requestedHost !== state.host)) {
            throw new Error('The existing session does not match this project, task, and host.');
        }
        return state.host;
    });
}
function stopSession() {
    return withSessionLock(() => {
        const state = inspectSession();
        const hasAttachments = fs.existsSync(attachmentDir);
        if (state.state === 'unavailable' || (state.state === 'session_not_found' && !hasAttachments)) {
            throw new Error('The task session is not available to stop.');
        }
        if (hasAttachments) validateAttachmentDirectory();
        const stopped = state.state === 'session_running';
        if (stopped && tmuxCommand(['kill-session', '-t', '=' + session]).status !== 0) throw new Error('The task session could not be stopped.');
        if (hasAttachments) {
            try { fs.rmSync(attachmentDir, { recursive: true }); }
            catch (_) { throw new Error(stopped ? 'Session stopped, but task images could not be removed.' : 'Task images could not be removed.'); }
        }
        return { stopped, attachments_removed: hasAttachments };
    });
}
async function main() {
    if (options.status) {
        const status = inspectSession();
        if (options.format === 'json') console.log(JSON.stringify(status));
        else console.log(`Session: ${status.state}${status.host ? ` (${status.host})` : ''}`);
        if (status.state === 'unavailable') process.exitCode = 2;
        return;
    }
    if (options.stop) {
        const result = stopSession();
        console.log(result.stopped ? 'The coding-host session was stopped. The governed task was not closed.'
            : 'The session had already ended; its task images were removed.');
        return;
    }
    // The UI and terminal use distinct loopback origins. Cookies are scoped to
    // the same local host, but browser same-origin rules isolate their scripts.
    server = http.createServer(requestHandler);
    server.on('error', () => { console.error('The local cockpit could not bind its port.'); cleanup(1); });
    await new Promise((resolve) => server.listen(port, '127.0.0.1', resolve));
    terminalServer = http.createServer(terminalRequest);
    terminalServer.on('upgrade', upgrade);
    terminalServer.on('error', () => { console.error('The local terminal could not bind its port.'); cleanup(1); });
    await new Promise((resolve) => terminalServer.listen(0, '127.0.0.1', resolve));
    terminalHost = '127.0.0.1:' + terminalServer.address().port;
    terminalOrigin = 'http://' + terminalHost;
    // PHP binds port 0 itself, so another process cannot steal a reserved port.
    phpPort = await new Promise((resolve, reject) => {
        let startup = '';
        const timer = setTimeout(() => reject(new Error('The local UI did not start.')), 5000);
        child('php', ['-S', '127.0.0.1:0', '-t', path.join(packageRoot, 'public'), path.join(packageRoot, 'public/index.php')], {
            ...process.env, AGENT_UI_PROJECT_ROOT: root, AGENT_UI_COMPOSER_AUTOLOAD: autoload,
            AGENT_UI_TERMINAL_TASK: options.task, AGENT_UI_TERMINAL_ORIGIN: terminalOrigin,
        }, (data) => {
            startup = (startup + data).slice(-4096);
            const match = /Development Server \(http:\/\/127\.0\.0\.1:(\d+)\) started/.exec(startup);
            if (match) { clearTimeout(timer); resolve(Number(match[1])); }
        });
    });
    await waitFor(async () => (await getStatus(`http://127.0.0.1:${phpPort}/task/${encodeURIComponent(options.task)}/progress.json`)) === 200, 'The task is unavailable in the selected project.');
    const initialState = inspectSession();
    const host = options.serve
        ? (initialState.state === 'session_running' ? initialState.host : null)
        : ensureSession(options.start || null, Boolean(options.start));
    const terminalEnv = { ...process.env, TMUX: '' };
    delete terminalEnv.TMUX_TMPDIR;
    let terminalExited = false;
    child('ttyd', ['-i', socketPath, '-b', '/_terminal', '-W', '-O', '-m', '1', 'tmux', '-L', 'agent-ui', 'attach-session', '-t', '=' + session], terminalEnv, null, () => {
        terminalExited = true;
        terminalAvailable = false;
    });
    for (let attempt = 0; attempt < 50 && !terminalExited; attempt++) {
        try { if (fs.statSync(socketPath).isSocket()) { terminalAvailable = true; break; } } catch (_) { /* waiting */ }
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    console.log(`Open http://${expectedHost}/_local/access#${accessToken}`);
    console.log(`Task ${options.task}; host ${host || 'not started'}; tmux session ${session}; project ${root}`);
    console.log(`Normal terminal: env -u TMUX -u TMUX_TMPDIR tmux -L agent-ui attach-session -t =${session}`);
    if (!terminalAvailable) console.log('Terminal transport unavailable; workflow navigation remains available.');
    console.log('Stopping this transport disconnects the browser and leaves the tmux session running.');
}
main().catch((error) => { console.error(error.message); cleanup(1); });
