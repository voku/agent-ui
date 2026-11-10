#!/usr/bin/env node
'use strict';

// Experimental, local-only transport for one explicitly started coding host.
// Workflow progress and permissions remain with agent-loop and the host.
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { StringDecoder } = require('node:string_decoder');
const { ClaudeStreamActivity } = require('./claude-stream-activity.cjs');

const usage = 'Usage: node tools/terminal/local-cockpit.cjs --root=PATH --task=ID (--serve | --start=shell|agy|codex|claude | --attach | --status | --stop) [--port=8090] [--format=json|text]';
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
    (options.start && !['shell', 'agy', 'codex', 'claude'].includes(options.start))) fail(usage);
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
const attachmentDir = path.join(fs.realpathSync(os.tmpdir()), `agent-ui-attachments-${process.getuid()}-${lockId}`);
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
function ensureAttachmentDirectory() {
    try { fs.mkdirSync(attachmentDir, { mode: 0o700 }); }
    catch (error) { if (error.code !== 'EEXIST') throw error; }
    const stat = fs.lstatSync(attachmentDir);
    if (!stat.isDirectory() || stat.isSymbolicLink() || stat.uid !== process.getuid() || (stat.mode & 0o077) !== 0) {
        throw new Error('Private attachment directory has invalid ownership or permissions.');
    }
}
const accessToken = crypto.randomBytes(32).toString('base64url');
const cookieSecret = crypto.randomBytes(32).toString('base64url');
const csrfToken = crypto.randomBytes(32).toString('base64url');
// Separate from browser credentials: only this process and the child PHP
// backend know it. It never travels in browser traffic or JSON responses.
const internalProxySecret = crypto.randomBytes(32).toString('base64url');
const cookieName = 'agent_ui_terminal_' + crypto.randomBytes(8).toString('hex');
const expectedHost = '127.0.0.1:' + port;
const expectedOrigin = 'http://' + expectedHost;
const children = [];
let server;
let terminalServer;
let terminalHost;
let terminalOrigin;
let terminalAvailable = false;
let terminalError = null;
let quitting = false;
const upgradedSockets = new Set();
let pendingSocket = null;
let attachmentBusy = false;
let codexAppServer = null;
let claudeActivity = null;

const appServerEventLimit = 40;
const appServerPendingLimit = 8;
const appServerInputLimit = 12 * 1024;
const appServerLineLimit = 256 * 1024;
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
function boundedAppText(value, maximum = 240) {
    if (typeof value !== 'string') return '';
    const clean = value
        .replace(/[\u0000-\u001f\u007f]/g, ' ')
        .replace(/(authorization\s*:\s*bearer\s+)[^\s,'";]+/gi, '$1[redacted]')
        .replace(/(\bbearer\s+)[^\s,'";]+/gi, '$1[redacted]')
        .replace(/\b(?:sk-[A-Za-z0-9_-]{16,}|gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[A-Z0-9]{16})\b/g, '[redacted]')
        .replace(/((?:api[_-]?key|token|secret|password)\s*[:=]\s*["']?)[^\s,'";&]+/gi, '$1[redacted]')
        .replace(/((?:--)?(?:api[_-]?key|token|secret|password)\s+["']?)[^\s,'";&]+/gi, '$1[redacted]')
        .trim();
    return clean.length > maximum ? clean.slice(0, maximum - 1) + '…' : clean;
}
function codexAppServerAvailable() {
    try { hostExecutable('codex'); return true; } catch (_) { return false; }
}
function recordAppServerActivity(runtime, kind, label) {
    const entry = {
        id: runtime.nextEventId++,
        at: new Date().toISOString(),
        kind: boundedAppText(kind, 32),
        label: boundedAppText(label, 160),
    };
    runtime.activity.push(entry);
    if (runtime.activity.length > appServerEventLimit) runtime.activity.splice(0, runtime.activity.length - appServerEventLimit);
    runtime.lastUpdated = entry.at;
}
function isClaudeAvailable() {
    try { hostExecutable('claude'); return true; } catch (_) { return false; }
}
function getClaudeActivity() {
    if (!claudeActivity) claudeActivity = new ClaudeStreamActivity(isClaudeAvailable() ? hostExecutable('claude') : 'claude', root, options.task);
    return claudeActivity;
}
function appServerSnapshot() {
    const available = codexAppServerAvailable();
    const runtime = codexAppServer;
    const state = runtime ? runtime.state : (available ? 'stopped' : 'unavailable');
    const messages = {
        stopped: 'No Codex App Server session is running.',
        unavailable: 'Codex CLI is unavailable on this host.',
        starting: 'Starting Codex App Server and checking local sign-in…',
        idle: 'Codex is ready for a prompt. This activity feed is observational.',
        running: 'Codex is working. This activity feed is observational.',
        waiting_approval: 'Codex is waiting for a deliberate approval decision.',
        waiting_input: 'Codex is waiting for a request this panel cannot answer.',
        stopping: 'Stopping the Codex App Server session…',
        error: runtime && runtime.errorMessage ? runtime.errorMessage : 'Codex App Server could not start. Check the CLI and sign-in.',
    };
    return {
        provider: 'codex',
        supports_decisions: true,
        available,
        running: Boolean(runtime && runtime.process && runtime.process.exitCode === null && runtime.process.signalCode === null),
        state,
        message: messages[state] || 'Codex App Server status is unavailable.',
        last_updated: runtime ? runtime.lastUpdated : null,
        activity: runtime ? runtime.activity.slice(-appServerEventLimit) : [],
        pending_requests: runtime ? Array.from(runtime.pendingRequests.values(), (entry) => entry.public) : [],
        pending_overflow: Boolean(runtime && runtime.pendingOverflow),
        csrf: csrfToken,
    };
}
function updateAppServerState(runtime) {
    if (runtime !== codexAppServer || ['starting', 'stopping', 'stopped', 'unavailable', 'error'].includes(runtime.state)) return;
    if (runtime.pendingRequests.size > 0 || runtime.pendingOverflow) {
        const supportedApproval = Array.from(runtime.pendingRequests.values()).some((entry) => entry.supportedApproval);
        runtime.state = supportedApproval ? 'waiting_approval' : 'waiting_input';
    } else {
        runtime.state = runtime.activeTurn ? 'running' : 'idle';
    }
}
function appServerFailure(runtime, message) {
    // A deliberate Stop will close the child pipes. Late EPIPE/errors from
    // those pipes must not turn a successful shutdown into a failed session.
    if (runtime !== codexAppServer || runtime.expectedStop) return;
    runtime.activeTurn = false;
    runtime.currentTurnId = null;
    runtime.state = 'error';
    runtime.errorMessage = message;
    runtime.pendingRequests.clear();
    runtime.protocolRequestTokens.clear();
    runtime.pendingOverflow = false;
    recordAppServerActivity(runtime, 'error', 'Codex App Server session encountered an error.');
    for (const pending of runtime.pendingRpc.values()) {
        clearTimeout(pending.timer);
        pending.reject(new Error('Codex App Server request failed.'));
    }
    runtime.pendingRpc.clear();
}
function sendAppServerMessage(runtime, message) {
    if (!runtime.process || runtime.process.stdin.destroyed || runtime.process.stdin.writableEnded) return false;
    try {
        runtime.process.stdin.write(JSON.stringify(message) + '\n');
        return true;
    } catch (_) {
        return false;
    }
}
function appServerRequest(runtime, method, params) {
    if (runtime !== codexAppServer || !runtime.process || runtime.process.exitCode !== null || runtime.process.signalCode !== null) {
        return Promise.reject(new Error('Codex App Server is not running.'));
    }
    const id = runtime.nextRpcId++;
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            runtime.pendingRpc.delete(String(id));
            reject(new Error('Codex App Server did not respond.'));
        }, 30000);
        runtime.pendingRpc.set(String(id), { resolve, reject, timer, method });
        if (!sendAppServerMessage(runtime, { id, method, params })) {
            clearTimeout(timer);
            runtime.pendingRpc.delete(String(id));
            reject(new Error('Codex App Server request could not be sent.'));
        }
    });
}
function appServerNotification(runtime, method, params) {
    if (runtime !== codexAppServer || !params || typeof params !== 'object') return;
    if (params.threadId && runtime.threadId && params.threadId !== runtime.threadId) return;
    // Unscoped item notifications must not alter human approval context, and
    // unscoped resolved notifications must not dismiss pending decisions.
    if (['item/started', 'item/completed', 'serverRequest/resolved'].includes(method) &&
        params.threadId !== runtime.threadId) return;
    if (method === 'turn/started') {
        if (params.threadId && params.threadId !== runtime.threadId) return;
        runtime.currentTurnId = params.turn && typeof params.turn.id === 'string' ? params.turn.id : null;
        runtime.activeTurn = true;
        recordAppServerActivity(runtime, 'turn', 'Codex started a turn.');
    } else if (method === 'turn/completed') {
        if (params.threadId && params.threadId !== runtime.threadId) return;
        const completedId = params.turn && params.turn.id;
        if (runtime.currentTurnId && completedId && completedId !== runtime.currentTurnId) return;
        runtime.activeTurn = false;
        runtime.currentTurnId = null;
        // A resolved notification is normally sent by Codex. A turn can also
        // be interrupted: never retain clickable approvals for a finished turn.
        // Only one turn may be active in this cockpit. Once it ends, every
        // outstanding approval token is stale, including requests whose
        // protocol payload omitted turnId. Never leave those clickable.
        runtime.pendingRequests.clear();
        runtime.protocolRequestTokens.clear();
        runtime.pendingOverflow = false;
        runtime.commands.clear();
        runtime.fileChanges.clear();
        const status = params.turn && params.turn.status;
        recordAppServerActivity(runtime, 'turn', status === 'completed' ? 'Codex completed the turn.' : 'Codex turn ended (' + boundedAppText(status || 'unknown', 32) + ').');
    } else if (method === 'item/started' || method === 'item/completed') {
        const item = params.item;
        if (!item || typeof item.type !== 'string') return;
        const labels = {
            agentMessage: 'response', plan: 'plan', reasoning: 'reasoning', commandExecution: 'command', fileChange: 'file change',
            mcpToolCall: 'integration', dynamicToolCall: 'tool', webSearch: 'web search', imageView: 'image view', collabToolCall: 'sub-agent',
        };
        const kind = Object.hasOwn(labels, item.type) ? labels[item.type] : null;
        if (kind === null) return;
        const completed = method === 'item/completed';
        const status = completed && typeof item.status === 'string' ? item.status : null;
        let verb = completed ? 'completed' : 'started';
        if (status === 'failed') verb = 'failed';
        else if (status === 'declined') verb = 'was declined';
        if (item.type === 'commandExecution' && typeof item.id === 'string') {
            if (completed) {
                runtime.commands.delete(item.id);
            } else {
                const value = Array.isArray(item.command)
                    ? item.command.filter((part) => typeof part === 'string').join(' ')
                    : item.command;
                if (typeof value === 'string' && value.trim()) {
                    runtime.commands.set(item.id, boundedAppText(value, 480));
                    while (runtime.commands.size > appServerEventLimit) runtime.commands.delete(runtime.commands.keys().next().value);
                }
            }
        }
        if (item.type === 'fileChange' && typeof item.id === 'string') {
            if (completed) {
                runtime.fileChanges.delete(item.id);
            } else if (Array.isArray(item.changes)) {
                const changes = item.changes.slice(0, 12).map((change) => {
                    if (!change || typeof change !== 'object') return null;
                    const operation = boundedAppText(change.kind, 24);
                    const file = boundedAppText(change.path, 160);
                    return file ? (operation ? operation + ' ' : '') + file : null;
                }).filter((change) => change !== null);
                runtime.fileChanges.set(item.id, changes);
                while (runtime.fileChanges.size > appServerEventLimit) {
                    const oldest = runtime.fileChanges.keys().next().value;
                    if (typeof oldest !== 'string') break;
                    runtime.fileChanges.delete(oldest);
                }
            }
        }
        recordAppServerActivity(runtime, kind, 'Codex ' + kind + ' ' + verb + '.');
    } else if (method === 'serverRequest/resolved') {
        const requestId = params.requestId;
        const token = runtime.protocolRequestTokens.get(String(requestId));
        if (token) {
            runtime.pendingRequests.delete(token);
            runtime.protocolRequestTokens.delete(String(requestId));
            recordAppServerActivity(runtime, 'request', 'A Codex request was resolved.');
        }
    }
    updateAppServerState(runtime);
}
function receiveAppServerRequest(runtime, message) {
    if (!Number.isSafeInteger(message.id) && typeof message.id !== 'string') return;
    const method = message.method;
    const params = message.params && typeof message.params === 'object' ? message.params : {};
    if (!runtime.threadId || params.threadId !== runtime.threadId) {
        recordAppServerActivity(runtime, 'request', 'A request outside the active Codex thread was not offered for approval.');
        return;
    }
    // Late requests from a completed turn must never become browser approval
    // buttons, even when a JSON-RPC id is recycled. A missing turnId is
    // tolerated only while an active turn exists.
    if (!runtime.activeTurn || (typeof params.turnId === 'string' &&
        (!runtime.currentTurnId || params.turnId !== runtime.currentTurnId))) {
        recordAppServerActivity(runtime, 'request', 'A request outside the active Codex turn was not offered for approval.');
        return;
    }
    // JSON-RPC ids can be reused after a request resolves. Never leave the old
    // one-time browser token usable for a different request sharing that id.
    const previous = runtime.protocolRequestTokens.get(String(message.id));
    if (previous) runtime.pendingRequests.delete(previous);
    runtime.protocolRequestTokens.delete(String(message.id));
    const token = crypto.randomBytes(12).toString('base64url');
    const decisions = Array.isArray(params.availableDecisions) ? params.availableDecisions : null;
    let entry;
    if (method === 'item/commandExecution/requestApproval') {
        const network = params.networkApprovalContext;
        const networkTarget = network && typeof network.host === 'string'
            ? 'Network access to ' + boundedAppText(network.host, 120) + (typeof network.protocol === 'string' ? ' (' + boundedAppText(network.protocol, 20) + ')' : '')
            : null;
        const command = Array.isArray(params.command) ? params.command.filter((part) => typeof part === 'string').join(' ') : params.command;
        const observed = typeof params.itemId === 'string' ? runtime.commands.get(params.itemId) : null;
        const preview = typeof command === 'string' && command.trim() ? boundedAppText(command, 480) : observed || '';
        const hasApprovalContext = Boolean(networkTarget || preview);
        entry = {
            supportedApproval: hasApprovalContext,
            protocolId: message.id,
            turnId: typeof params.turnId === 'string' ? params.turnId : null,
            public: {
                token,
                kind: hasApprovalContext ? 'command' : 'unsupported',
                title: networkTarget ? 'Codex requests network access' : 'Codex requests command approval',
                summary: networkTarget || (preview ? 'Command: ' + preview :
                    'Command details are unavailable. Approval is disabled; stop the session to cancel.'),
                cwd: boundedAppText(params.cwd, 240),
                reason: boundedAppText(params.reason, 240),
                can_accept: hasApprovalContext && (decisions === null || decisions.includes('accept')),
                can_decline: hasApprovalContext && (decisions === null || decisions.includes('decline')),
                can_cancel: hasApprovalContext && decisions !== null && decisions.includes('cancel'),
            },
        };
    } else if (method === 'item/fileChange/requestApproval') {
        const changedFiles = typeof params.itemId === 'string' ? runtime.fileChanges.get(params.itemId) : null;
        const requestedChanges = changedFiles && changedFiles.length ? 'Requested changes:\n' + changedFiles.join('\n') : null;
        const fileContext = requestedChanges || (typeof params.grantRoot === 'string' && params.grantRoot.trim()
            ? 'Requested workspace root: ' + boundedAppText(params.grantRoot, 240) : null);
        entry = {
            supportedApproval: Boolean(fileContext),
            protocolId: message.id,
            turnId: typeof params.turnId === 'string' ? params.turnId : null,
            public: {
                token,
                kind: fileContext ? 'file_change' : 'unsupported',
                title: 'Codex requests file change approval',
                summary: fileContext ? boundedAppText(fileContext, 900) :
                    'File details are unavailable. Approval is disabled; stop the session to cancel.',
                cwd: '',
                reason: boundedAppText(params.reason, 240),
                can_accept: Boolean(fileContext) && (decisions === null || decisions.includes('accept')),
                can_decline: Boolean(fileContext) && (decisions === null || decisions.includes('decline')),
                can_cancel: Boolean(fileContext) && decisions !== null && decisions.includes('cancel'),
            },
        };
    } else {
        const titles = {
            'item/permissions/requestApproval': 'Codex requests additional permissions',
            'tool/requestUserInput': 'Codex is waiting for user input',
            'mcpServer/elicitation/request': 'An integration requests user input',
        };
        entry = {
            supportedApproval: false,
            protocolId: message.id,
            public: {
                token,
                kind: 'unsupported',
                title: Object.hasOwn(titles, method) ? titles[method] : 'Codex sent an unsupported request',
                summary: 'This request cannot be answered in the cockpit. Stop this App Server session to cancel it.',
                cwd: '',
                reason: boundedAppText(params.reason || params.message, 240),
                can_accept: false,
                can_decline: false,
                can_cancel: false,
            },
        };
    }
    if (runtime.pendingRequests.size >= appServerPendingLimit) {
        runtime.pendingOverflow = true;
        recordAppServerActivity(runtime, 'request', 'Codex sent more simultaneous requests than the cockpit can display.');
        updateAppServerState(runtime);
        return;
    }
    runtime.pendingRequests.set(token, entry);
    runtime.protocolRequestTokens.set(String(message.id), token);
    recordAppServerActivity(runtime, 'request', entry.public.title + '.');
    updateAppServerState(runtime);
}
function handleAppServerMessage(runtime, line) {
    let message;
    try { message = JSON.parse(line); } catch (_) {
        appServerFailure(runtime, 'Codex App Server sent an unreadable event. Stop and restart the session.');
        if (runtime.process) runtime.process.kill('SIGTERM');
        return;
    }
    if (!message || typeof message !== 'object' || Array.isArray(message)) return;
    if (typeof message.method === 'string') {
        if (Object.hasOwn(message, 'id')) receiveAppServerRequest(runtime, message);
        else appServerNotification(runtime, message.method, message.params);
        return;
    }
    if (Object.hasOwn(message, 'id')) {
        const pending = runtime.pendingRpc.get(String(message.id));
        if (!pending) return;
        runtime.pendingRpc.delete(String(message.id));
        clearTimeout(pending.timer);
        if (message.error) pending.reject(new Error('Codex App Server request failed.'));
        else pending.resolve(message.result || {});
    }
}
function readAppServerOutput(runtime, chunk) {
    runtime.lineBuffer += runtime.decoder.write(chunk);
    if (runtime.lineBuffer.length > appServerLineLimit && !runtime.lineBuffer.includes('\n')) {
        appServerFailure(runtime, 'Codex App Server sent an oversized event. Stop and restart the session.');
        if (runtime.process) runtime.process.kill('SIGTERM');
        return;
    }
    let newline;
    while ((newline = runtime.lineBuffer.indexOf('\n')) !== -1) {
        const line = runtime.lineBuffer.slice(0, newline).replace(/\r$/, '');
        runtime.lineBuffer = runtime.lineBuffer.slice(newline + 1);
        if (line.length > appServerLineLimit) {
            appServerFailure(runtime, 'Codex App Server sent an oversized event. Stop and restart the session.');
            if (runtime.process) runtime.process.kill('SIGTERM');
            return;
        }
        if (line) handleAppServerMessage(runtime, line);
    }
}
function createAppServerRuntime() {
    return {
        process: null,
        state: 'starting',
        errorMessage: null,
        threadId: null,
        activeTurn: false,
        currentTurnId: null,
        expectedStop: false,
        nextRpcId: 1,
        nextEventId: 1,
        pendingRpc: new Map(),
        pendingRequests: new Map(),
        protocolRequestTokens: new Map(),
        fileChanges: new Map(),
        commands: new Map(),
        pendingOverflow: false,
        activity: [],
        lastUpdated: null,
        lineBuffer: '',
        decoder: new StringDecoder('utf8'),
    };
}
async function startCodexAppServer() {
    if (codexAppServer && ['starting', 'idle', 'running', 'waiting_approval', 'waiting_input', 'stopping'].includes(codexAppServer.state)) {
        throw new Error('A Codex App Server session is already active.');
    }
    let executable;
    try { executable = hostExecutable('codex'); }
    catch (_) { throw new Error('Codex CLI is not available on this host.'); }
    const runtime = createAppServerRuntime();
    codexAppServer = runtime;
    recordAppServerActivity(runtime, 'session', 'Starting Codex App Server.');
    let processChild;
    try {
        processChild = spawn(executable, ['app-server'], {
            cwd: root,
            env: { ...process.env, AGENT_LOOP_TASK_ID: options.task, AGENT_UI_PROJECT_ROOT: root },
            stdio: ['pipe', 'pipe', 'pipe'],
        });
    } catch (_) {
        appServerFailure(runtime, 'Codex App Server could not start. Check the CLI and sign-in.');
        throw new Error('Codex App Server could not start. Check the CLI and sign-in.');
    }
    runtime.process = processChild;
    children.push(processChild);
    processChild.stdout.on('data', (data) => readAppServerOutput(runtime, data));
    // stderr may contain authentication or provider diagnostics; never relay it to the browser or logs.
    processChild.stderr.on('data', () => {});
    processChild.stdin.on('error', () => appServerFailure(runtime, 'Codex App Server connection closed. Stop and restart the session.'));
    processChild.on('error', () => appServerFailure(runtime, 'Codex App Server could not start. Check the CLI and sign-in.'));
    processChild.on('exit', () => {
        if (runtime !== codexAppServer) return;
        for (const pending of runtime.pendingRpc.values()) {
            clearTimeout(pending.timer);
            pending.reject(new Error('Codex App Server stopped.'));
        }
        runtime.pendingRpc.clear();
        runtime.process = null;
        runtime.activeTurn = false;
        runtime.pendingRequests.clear();
        runtime.protocolRequestTokens.clear();
        runtime.pendingOverflow = false;
        if (runtime.expectedStop) {
            if (runtime.state !== 'stopped') {
                runtime.state = 'stopped';
                runtime.errorMessage = null;
                recordAppServerActivity(runtime, 'session', 'Codex App Server stopped.');
            }
        } else {
            appServerFailure(runtime, 'Codex App Server exited. Check the CLI and sign-in, then start a new session.');
        }
    });
    try {
        await appServerRequest(runtime, 'initialize', {
            clientInfo: { name: 'voku_agent_ui', title: 'voku/agent-ui local cockpit', version: '0.1.0' },
            capabilities: { optOutNotificationMethods: ['item/agentMessage/delta', 'item/commandExecution/outputDelta', 'item/reasoning/summaryTextDelta', 'item/reasoning/textDelta', 'item/plan/delta', 'item/fileChange/outputDelta'] },
        });
        if (!sendAppServerMessage(runtime, { method: 'initialized', params: {} })) throw new Error('Codex App Server connection closed.');
        const threadResult = await appServerRequest(runtime, 'thread/start', {
            cwd: root,
            approvalPolicy: 'on-request',
            sandbox: 'workspace-write',
        });
        if (!threadResult.thread || typeof threadResult.thread.id !== 'string') throw new Error('Codex App Server did not start a thread.');
        runtime.threadId = threadResult.thread.id;
        runtime.state = 'idle';
        runtime.errorMessage = null;
        recordAppServerActivity(runtime, 'session', 'Codex App Server is ready for a prompt.');
        return appServerSnapshot();
    } catch (_) {
        appServerFailure(runtime, 'Codex App Server could not initialize. Check the CLI version and sign-in.');
        if (runtime.process && runtime.process.exitCode === null && runtime.process.signalCode === null) runtime.process.kill('SIGTERM');
        throw new Error('Codex App Server could not initialize. Check the CLI version and sign-in.');
    }
}
async function stopCodexAppServer() {
    const runtime = codexAppServer;
    if (!runtime || !runtime.process) {
        if (runtime) runtime.state = 'stopped';
        return;
    }
    const processChild = runtime.process;
    runtime.expectedStop = true;
    runtime.state = 'stopping';
    await new Promise((resolve) => {
        if (processChild.exitCode !== null || processChild.signalCode !== null) return resolve();
        const timeout = setTimeout(resolve, 1500);
        processChild.once('exit', () => { clearTimeout(timeout); resolve(); });
        processChild.kill('SIGTERM');
    });
    const stopEventAlreadyRecorded = runtime.state === 'stopped';
    if (processChild.exitCode === null && processChild.signalCode === null) processChild.kill('SIGKILL');
    runtime.process = null;
    runtime.activeTurn = false;
    runtime.pendingRequests.clear();
    runtime.protocolRequestTokens.clear();
    runtime.pendingOverflow = false;
    runtime.state = 'stopped';
    runtime.errorMessage = null;
    if (!stopEventAlreadyRecorded) recordAppServerActivity(runtime, 'session', 'Codex App Server stopped.');
}
async function submitCodexTurn(prompt) {
    const runtime = codexAppServer;
    if (!runtime || runtime.state !== 'idle' || !runtime.threadId || runtime.pendingRequests.size || runtime.pendingOverflow) {
        throw new Error('Codex is not ready for a new turn.');
    }
    runtime.activeTurn = true;
    runtime.state = 'running';
    recordAppServerActivity(runtime, 'turn', 'A prompt was sent to Codex.');
    try {
        await appServerRequest(runtime, 'turn/start', {
            threadId: runtime.threadId,
            input: [{ type: 'text', text: prompt }],
        });
    } catch (_) {
        if (runtime === codexAppServer) {
            appServerFailure(runtime, 'Codex could not confirm the requested turn. Stop and restart the session before sending another prompt.');
            if (runtime.process && runtime.process.exitCode === null && runtime.process.signalCode === null) runtime.process.kill('SIGTERM');
        }
        throw new Error('Codex could not confirm the requested turn. Stop and restart the session before sending another prompt.');
    }
    return appServerSnapshot();
}
function resolveCodexApproval(token, decision) {
    const runtime = codexAppServer;
    const entry = runtime && runtime.pendingRequests.get(token);
    if (!runtime || !entry || !entry.supportedApproval || !runtime.process || !runtime.activeTurn ||
        (entry.turnId && (!runtime.currentTurnId || entry.turnId !== runtime.currentTurnId))) {
        throw new Error('This Codex request is no longer available. Refresh the activity panel.');
    }
    if (!['accept', 'decline', 'cancel'].includes(decision) || (decision === 'accept' && !entry.public.can_accept) ||
        (decision === 'decline' && !entry.public.can_decline) || (decision === 'cancel' && !entry.public.can_cancel)) {
        throw new Error('That decision is unavailable for this Codex request.');
    }
    const response = { id: entry.protocolId, result: { decision } };
    if (!sendAppServerMessage(runtime, response)) throw new Error('The Codex decision could not be sent.');
    runtime.pendingRequests.delete(token);
    runtime.protocolRequestTokens.delete(String(entry.protocolId));
    recordAppServerActivity(runtime, 'approval', decision === 'accept' ? 'A human approved a Codex request once.' : 'A human declined or canceled a Codex request.');
    updateAppServerState(runtime);
    return appServerSnapshot();
}
function readJsonBody(req, res, maximumBytes) {
    return new Promise((resolve) => {
        let body = '';
        let receivedBytes = 0;
        let settled = false;
        req.on('data', (chunk) => {
            if (settled) return;
            receivedBytes += chunk.length;
            if (receivedBytes > maximumBytes) {
                settled = true;
                deny(res, 413, 'Request is too large.');
                req.resume();
                resolve(null);
                return;
            }
            body += chunk;
        });
        req.on('end', () => {
            if (settled) return;
            try {
                const value = JSON.parse(body);
                if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('invalid');
                settled = true;
                resolve(value);
            } catch (_) {
                settled = true;
                deny(res, 400, 'Invalid request.');
                resolve(null);
            }
        });
        req.on('aborted', () => { if (!settled) { settled = true; resolve(null); } });
        req.on('error', () => { if (!settled) { settled = true; resolve(null); } });
    });
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
        return json(res, 200, {
            ...state,
            terminal_available: terminalAvailable,
            terminal_error: terminalError,
            hosts: hostAvailability(),
            attach_command: state.state === 'session_running' ? 'tmux -L agent-ui attach-session -t =' + session : null,
            csrf: csrfToken,
        });
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
                    if (!['shell', 'agy', 'codex', 'claude'].includes(requestedHost)) return deny(res, 400, 'Unsupported terminal host.');
                    if (!terminalAvailable) return deny(res, 503, 'The terminal transport is unavailable.');
                    const taskStatus = await getStatus(`http://127.0.0.1:${phpPort}/task/${encodeURIComponent(options.task)}/progress.json`);
                    if (taskStatus !== 200) return deny(res, 409, 'The task is unavailable in the selected project.');
                    const host = ensureSession(requestedHost, true);
                    return json(res, 200, { state: 'session_running', host });
                }
                stopSession();
                return json(res, 200, { state: 'session_not_found' });
            } catch (error) {
                return deny(res, 409, error.message || 'The session action failed.');
            }
        });
    }
    const selectedActivity = /^\/_local\/agent-activity\/(codex|claude)(?:\/(start|stop|turn|decision))?$/.exec(req.url);
    const legacyCodex = /^\/_local\/codex-app-server(?:\/(start|stop|turn|decision))?$/.exec(req.url);
    const activityProvider = selectedActivity ? selectedActivity[1] : legacyCodex ? 'codex' : null;
    const activityAction = selectedActivity ? selectedActivity[2] : legacyCodex ? legacyCodex[1] : null;
    function activitySnapshot() {
        if (activityProvider === 'codex') return appServerSnapshot();
        return { ...getClaudeActivity().snapshot(isClaudeAvailable()), csrf: csrfToken };
    }
    if (req.method === 'GET' && activityProvider && !activityAction) return json(res, 200, activitySnapshot());
    const appServerAction = req.method === 'POST' && activityProvider && activityAction ? [null, activityAction] : null;
    if (appServerAction) {
        if (req.headers.origin !== expectedOrigin || req.headers['content-type'] !== 'application/json' ||
            !equal(req.headers['x-agent-ui-csrf'], csrfToken)) return deny(res, 403, 'Origin and CSRF token required.');
        if (Number(req.headers['content-length'] || 0) > appServerInputLimit + 1024) return deny(res, 413, 'Request is too large.');
        return readJsonBody(req, res, appServerInputLimit + 1024).then(async (body) => {
            if (body === null || res.writableEnded) return;
            const action = appServerAction[1];
            try {
                if (action === 'start') {
                    const taskStatus = await getStatus(`http://127.0.0.1:${phpPort}/task/${encodeURIComponent(options.task)}/progress.json`);
                    if (taskStatus !== 200) return deny(res, 409, 'The task is unavailable in the selected project.');
                    if (activityProvider === 'codex') {
                        if (claudeActivity && claudeActivity.snapshot(true).running) throw new Error('Stop Claude before starting Codex.');
                        await startCodexAppServer();
                    } else {
                        if (codexAppServer && appServerSnapshot().running) throw new Error('Stop Codex before starting Claude.');
                        if (!isClaudeAvailable()) throw new Error('Claude CLI is unavailable on this host.');
                        const activity = getClaudeActivity();
                        activity.start();
                        children.push(activity.process);
                    }
                } else if (action === 'stop') {
                    if (activityProvider === 'codex') await stopCodexAppServer();
                    else await getClaudeActivity().stop();
                } else if (action === 'turn') {
                    if (typeof body.prompt !== 'string' || Buffer.byteLength(body.prompt, 'utf8') > appServerInputLimit) {
                        return deny(res, 400, 'Prompt must be text under 12 KiB.');
                    }
                    const prompt = body.prompt.trim();
                    if (!prompt) return deny(res, 400, 'Prompt cannot be empty.');
                    if (activityProvider === 'codex') await submitCodexTurn(prompt);
                    else getClaudeActivity().sendPrompt(prompt);
                } else {
                    if (activityProvider !== 'codex') return deny(res, 409, 'Claude tool decisions require an Agent SDK permission callback.');
                    if (typeof body.token !== 'string' || typeof body.decision !== 'string') return deny(res, 400, 'Invalid Codex decision.');
                    resolveCodexApproval(body.token, body.decision);
                }
                return json(res, 200, activitySnapshot());
            } catch (error) {
                return deny(res, action === 'start' ? 503 : 409, error instanceof Error ? error.message : 'The structured agent action failed.');
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
    if (!terminalAvailable || inspectSession().state !== 'session_running') {
        return deny(res, 409, 'Start an available task session before attaching an image.');
    }
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
            ensureAttachmentDirectory();
            if (fs.readdirSync(attachmentDir).length >= 32) {
                return deny(res, 409, 'Task image limit reached; stop the task session to clean up.');
            }
            filename = path.join(attachmentDir, crypto.randomBytes(16).toString('hex') + imageExtensions[type]);
            fs.writeFileSync(filename, bytes, { flag: 'wx', mode: 0o600 });
            // Paste only an internal generated path, never a shell command or Enter.
            // It remains the user's decision what to do with the image.
            const sent = tmuxCommand(['send-keys', '-l', '-t', '=' + session + ':', ' ' + filename]);
            if (sent.status !== 0) throw new Error('Unable to paste the image path into the terminal.');
            return json(res, 201, { path: filename });
        } catch (error) {
            if (filename) {
                try { fs.unlinkSync(filename); } catch (_) { /* retain error context */ }
            }
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
    delete headers['x-agent-ui-internal-secret'];
    if (terminal) delete headers.cookie;
    else headers['x-agent-ui-internal-secret'] = internalProxySecret;
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
        const request = http.get(url, { headers: { 'X-Agent-Ui-Internal-Secret': internalProxySecret } }, (response) => {
            response.resume();
            resolve(response.statusCode);
        });
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
        const preferred = process.env.SHELL;
        if (typeof preferred === 'string' && path.isAbsolute(preferred)) {
            try {
                fs.accessSync(preferred, fs.constants.X_OK);
                if (fs.statSync(preferred).isFile()) return preferred;
            } catch (_) { /* fallback */ }
        }
        for (const candidate of ['/bin/bash', '/usr/bin/bash', '/bin/sh']) {
            try {
                fs.accessSync(candidate, fs.constants.X_OK);
                if (fs.statSync(candidate).isFile()) return candidate;
            } catch (_) { /* next candidate */ }
        }
        fs.accessSync('/bin/sh', fs.constants.X_OK);
        return '/bin/sh';
    }
    const searchDirs = (process.env.PATH || '').split(path.delimiter).filter(Boolean);
    try {
        const homeLocalBin = path.join(os.homedir(), '.local/bin');
        if (!searchDirs.includes(homeLocalBin)) searchDirs.push(homeLocalBin);
    } catch (_) { /* ignore */ }
    for (const directory of searchDirs) {
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
    for (const name of ['shell', 'agy', 'codex', 'claude']) {
        try { hostExecutable(name); availability[name] = true; } catch (_) { availability[name] = false; }
    }
    return availability;
}
function inspectSession() {
    const target = '=' + session;
    try {
        const result = tmuxCommand(['has-session', '-t', target]);
        if (result.status === 1) return { state: 'session_not_found', host: null };
        if (result.status !== 0) return { state: 'unavailable', host: null };
        const location = tmuxCommand(['display-message', '-p', '-t', target + ':', '#{session_path}']);
        const identity = tmuxCommand(['show-options', '-v', '-t', target + ':', '@agent-ui-host']);
        const host = identity.stdout.trim();
        if (location.status !== 0 || fs.realpathSync(location.stdout.trim()) !== root ||
            identity.status !== 0 || !['shell', 'agy', 'codex', 'claude'].includes(host)) return { state: 'unavailable', host: null };
        return { state: 'session_running', host };
    } catch (_) { return { state: 'unavailable', host: null }; }
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
            if (!['shell', 'agy', 'codex', 'claude'].includes(requestedHost)) throw new Error('Unsupported coding host.');
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
            try { tmuxCommand(['set-option', '-t', target + ':', 'window-size', 'latest']); } catch (_) {}
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
        if (state.state !== 'session_running') throw new Error('The task session is not available to stop.');
        if (tmuxCommand(['kill-session', '-t', '=' + session]).status !== 0) throw new Error('The task session could not be stopped.');
        if (fs.existsSync(attachmentDir)) {
            ensureAttachmentDirectory();
            fs.rmSync(attachmentDir, { recursive: true });
        }
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
        stopSession();
        console.log('The coding-host session was stopped. The governed task was not closed.');
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
            AGENT_UI_INTERNAL_PROXY_SECRET: internalProxySecret,
            AGENT_UI_TERMINAL_TASK: options.task, AGENT_UI_TERMINAL_ORIGIN: terminalOrigin,
        }, (data) => {
            startup = (startup + data).slice(-4096);
            const match = /Development Server \(http:\/\/127\.0\.0\.1:(\d+)\) started/.exec(startup);
            if (match) { clearTimeout(timer); resolve(Number(match[1])); }
        });
    });
    await waitFor(async () => (await getStatus(`http://127.0.0.1:${phpPort}/task/${encodeURIComponent(options.task)}/progress.json`)) === 200, 'The task is unavailable in the selected project.');
    const missing = ['ttyd', 'tmux'].filter((name) => !(process.env.PATH || '').split(path.delimiter).some((directory) => {
        try {
            const candidate = path.join(directory || '.', name);
            fs.accessSync(candidate, fs.constants.X_OK);
            return fs.statSync(candidate).isFile();
        } catch (_) { return false; }
    }));
    if (missing.length) terminalError = `Missing required terminal ${missing.length === 1 ? 'tool' : 'tools'}: ${missing.join(', ')}. Install ${missing.length === 1 ? 'it' : 'them'} on PATH and restart the cockpit.`;
    if (!options.serve && terminalError) throw new Error(terminalError);
    const initialState = inspectSession();
    const host = options.serve
        ? (initialState.state === 'session_running' ? initialState.host : null)
        : ensureSession(options.start || null, Boolean(options.start));
    const terminalEnv = { ...process.env, TMUX: '' };
    delete terminalEnv.TMUX_TMPDIR;
    let terminalExited = missing.length > 0;
    if (!terminalExited) child('ttyd', ['-i', socketPath, '-b', '/_terminal', '-W', '-O', '-m', '1', 'tmux', '-L', 'agent-ui', 'attach-session', '-t', '=' + session], terminalEnv, null, () => {
        terminalExited = true;
        terminalAvailable = false;
        terminalError = 'The ttyd terminal transport stopped or could not start. Check the ttyd installation and restart the cockpit.';
    });
    for (let attempt = 0; attempt < 50 && !terminalExited; attempt++) {
        try { if (fs.statSync(socketPath).isSocket()) { terminalAvailable = true; break; } } catch (_) { /* waiting */ }
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    console.log(`Open http://${expectedHost}/_local/access#${accessToken}`);
    console.log(`Task ${options.task}; host ${host || 'not started'}; tmux session ${session}; project ${root}`);
    console.log(`Normal terminal: env -u TMUX -u TMUX_TMPDIR tmux -L agent-ui attach-session -t =${session}`);
    if (!terminalAvailable) console.log(`Terminal transport unavailable; workflow navigation remains available. ${terminalError || 'Check ttyd and restart the cockpit.'}`);
    console.log('Stopping this transport disconnects the browser and leaves the tmux session running.');
}
main().catch((error) => { console.error(error.message); cleanup(1); });
