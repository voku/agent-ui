'use strict';

// Browser and security regression check. Uses a fake host, real tmux and ttyd.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const packageRoot = path.resolve(__dirname, '../..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-ui-cockpit-check-'));
const project = path.join(temp, 'project');
const fakeBin = path.join(temp, 'bin');
const pidFile = path.join(temp, 'fake-host.pid');
fs.mkdirSync(fakeBin);
const quotedPidFile = "'" + pidFile.replace(/'/g, "'\\''") + "'";
fs.writeFileSync(path.join(fakeBin, 'codex'), '#!/bin/sh\nprintf "%s" "$$" > ' + quotedPidFile + '\nprintf "FAKE HOST READY\\n"\nwhile IFS= read -r line; do printf "received: %s\\n" "$line"; done\n', { mode: 0o755 });
const env = { ...process.env, PATH: fakeBin + path.delimiter + process.env.PATH };
let server, browser, session;
function command(executable, args) {
    const result = spawnSync(executable, args, { cwd: packageRoot, env, encoding: 'utf8', timeout: 20000 });
    if (result.error) throw result.error;
    return result;
}
function check(result, label) {
    if (result.status !== 0) throw new Error(`${label} failed: ${result.stderr}`);
}
function pause(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }
async function until(test, label, timeout = 15000) {
    const end = Date.now() + timeout;
    while (Date.now() < end) { if (await test()) return; await pause(100); }
    throw new Error('Timed out: ' + label);
}
function freePort() {
    return new Promise((resolve, reject) => {
        const listener = net.createServer();
        listener.on('error', reject);
        listener.listen(0, '127.0.0.1', () => { const port = listener.address().port; listener.close(() => resolve(port)); });
    });
}
function request(port, route, headers = {}, method = 'GET', body = '') {
    return new Promise((resolve, reject) => {
        const req = http.request({ host: '127.0.0.1', port, path: route, method, headers }, (res) => {
            let text = '';
            res.on('data', (chunk) => { text += chunk; });
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: text }));
        });
        req.on('error', reject);
        req.end(body);
    });
}
function websocketAttempt(port, headers) {
    return new Promise((resolve, reject) => {
        const req = http.request({ host: '127.0.0.1', port, path: '/_terminal/ws', headers: {
            Connection: 'Upgrade', Upgrade: 'websocket', 'Sec-WebSocket-Version': '13',
            'Sec-WebSocket-Protocol': 'tty', 'Sec-WebSocket-Key': crypto.randomBytes(16).toString('base64'), ...headers,
        } });
        req.on('upgrade', (_res, socket) => { socket.destroy(); resolve(101); });
        req.on('response', (res) => { res.resume(); resolve(res.statusCode); });
        req.on('error', reject);
        req.end();
    });
}
async function stopServer() {
    if (!server || server.exitCode !== null || server.signalCode !== null) return;
    const exited = new Promise((resolve) => server.once('exit', resolve));
    server.kill('SIGTERM');
    await Promise.race([exited, pause(7000).then(() => { throw new Error('Transport did not stop.'); })]);
}
async function run() {
    check(command('php', [path.join(packageRoot, 'tools/rendered/workflow-live-fixture.php'), project]), 'Fixture');
    const port = await freePort();
    const origin = `http://127.0.0.1:${port}`;
    const script = path.join(__dirname, 'local-cockpit.cjs');
    const baseArgs = [script, `--root=${project}`, '--task=UI-36'];
    server = spawn(process.execPath, [...baseArgs, '--start=codex', `--port=${port}`], { cwd: packageRoot, env, stdio: ['ignore', 'pipe', 'pipe'] });
    let output = '', errors = '';
    server.stdout.on('data', (chunk) => { output += chunk; });
    server.stderr.on('data', (chunk) => { errors += chunk; });
    await until(() => {
        if (server.exitCode !== null) throw new Error('Transport failed to start: ' + errors);
        return output.includes('Normal terminal:');
    }, 'transport startup');
    const accessUrl = output.match(/http:\/\/\S+/)[0];
    session = output.match(/tmux session ([^;]+)/)[1];
    await until(() => fs.existsSync(pidFile), 'fake host startup');
    const initialPid = fs.readFileSync(pidFile, 'utf8');

    assert.equal((await request(port, '/task/UI-36/progress')).status, 401);
    assert.equal((await request(port, '/_local/access', { Host: `attacker.example:${port}` })).status, 403);
    assert.equal((await request(port, '/_local/login', { Origin: 'https://attacker.example', 'Content-Type': 'application/json' }, 'POST', '{}')).status, 403);
    const duplicate = command(process.execPath, [...baseArgs, '--start=codex', `--port=${await freePort()}`]);
    assert.notEqual(duplicate.status, 0);
    assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid);

    browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}), args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
    const page = await context.newPage();
    await page.goto(accessUrl);
    await page.waitForURL('**/task/UI-36/progress');
    const cookies = await context.cookies(origin);
    const cookie = cookies.map((entry) => `${entry.name}=${entry.value}`).join('; ');
    const terminalUrl = new URL(await page.locator('[data-terminal-frame]').getAttribute('src'));
    const terminalPort = Number(terminalUrl.port);
    assert.notEqual(terminalPort, port, 'terminal has a separate browser origin');
    assert.equal((await request(port, '/_local/session', { Cookie: cookie })).status, 200);
    assert.equal((await request(port, '/_local/session', { Cookie: cookie, Origin: 'https://attacker.example' })).status, 403);
    assert.equal((await request(port, '/_local/session', { Cookie: cookie, Origin: terminalUrl.origin })).status, 403);
    assert.equal((await request(port, '/_terminal/', { Cookie: cookie })).status, 404);
    assert.equal((await request(terminalPort, '/_terminal/', { Cookie: cookie, 'Sec-Fetch-Site': 'cross-site' })).status, 403);
    assert.equal(await websocketAttempt(terminalPort, { Origin: terminalUrl.origin }), 403);
    assert.equal(await websocketAttempt(terminalPort, { Cookie: cookie, Origin: 'https://attacker.example' }), 403);
    const terminalResponse = await request(terminalPort, '/_terminal/', { Cookie: cookie, 'Sec-Fetch-Site': 'same-site' });
    assert.equal(terminalResponse.status, 200);
    assert.ok(terminalResponse.headers['content-security-policy'].includes('frame-ancestors ' + origin));
    assert.equal(terminalResponse.headers['x-frame-options'], undefined);

    const frame = page.frameLocator('[data-terminal-frame]');
    await frame.locator('.xterm-helper-textarea').waitFor({ timeout: 15000 });
    const child = page.frames().find((item) => item.url().startsWith(terminalUrl.origin));
    assert.equal(await child.evaluate(() => { try { return Boolean(parent.document.body); } catch (_) { return false; } }), false, 'terminal script cannot read the workflow page');
    await until(() => command('tmux', ['-L', 'agent-ui', 'display-message', '-p', '-t', '=' + session + ':', '#{session_attached}']).stdout.trim() === '1', 'terminal connection');
    await page.locator('[data-terminal-state]').getByText('tmux session running').waitFor();
    const [graphBox, terminalBox] = await Promise.all([page.locator('[data-workflow-graph]').boundingBox(), page.locator('[data-terminal-panel]').boundingBox()]);
    assert.ok(terminalBox.x >= graphBox.x + graphBox.width, 'desktop workflow and terminal appear side by side');
    await frame.locator('.xterm-helper-textarea').focus();
    await page.keyboard.type('browser terminal proof');
    await page.keyboard.press('Enter');
    await until(() => command('tmux', ['-L', 'agent-ui', 'capture-pane', '-p', '-t', '=' + session + ':']).stdout.includes('received: browser terminal proof'), 'terminal input');
    const secondViewer = await websocketAttempt(terminalPort, { Cookie: cookie, Origin: terminalUrl.origin });
    assert.notEqual(secondViewer, 101, 'only one writable browser viewer is accepted');
    await page.locator('[data-terminal-toggle]').click();
    assert.equal(await page.locator('[data-terminal-frame]').getAttribute('src'), 'about:blank');
    assert.equal(command('tmux', ['-L', 'agent-ui', 'has-session', '-t', '=' + session]).status, 0);
    await until(() => command('tmux', ['-L', 'agent-ui', 'display-message', '-p', '-t', '=' + session + ':', '#{session_attached}']).stdout.trim() === '0', 'viewer disconnect');
    await page.locator('[data-terminal-toggle]').click();
    await frame.locator('.xterm-helper-textarea').waitFor();
    await until(() => command('tmux', ['-L', 'agent-ui', 'display-message', '-p', '-t', '=' + session + ':', '#{session_attached}']).stdout.trim() === '1', 'viewer reconnect');
    await page.reload();
    await frame.locator('.xterm-helper-textarea').waitFor();
    await frame.locator('.xterm-helper-textarea').focus();
    await page.keyboard.type('after reload');
    await page.keyboard.press('Enter');
    await until(() => command('tmux', ['-L', 'agent-ui', 'capture-pane', '-p', '-t', '=' + session + ':']).stdout.includes('received: after reload'), 'terminal input after reload');
    assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid, 'reconnect did not restart the host');
    await page.goto(origin + '/task/UI-37/progress');
    assert.equal(await page.locator('[data-terminal-panel]').count(), 0, 'terminal is shown only on its task');
    await page.goto(origin + '/task/UI-36/progress');
    await frame.locator('.xterm-helper-textarea').waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    const mobileWidth = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    assert.ok(mobileWidth.scroll <= mobileWidth.client + 1, 'mobile cockpit does not overflow horizontally');

    check(command('tmux', ['-L', 'agent-ui', 'kill-session', '-t', '=' + session]), 'Stop fake session');
    await until(async () => JSON.parse((await request(port, '/_local/session', { Cookie: cookie })).body).state === 'session_not_found', 'session exit');
    await stopServer();
    const attach = command(process.execPath, [...baseArgs, '--attach', `--port=${await freePort()}`]);
    assert.notEqual(attach.status, 0, 'attach does not restart an exited host');
    assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid);
    console.log('Terminal browser, reconnect, task binding, origin, authentication and single-viewer checks passed.');
}
run().catch((error) => { console.error(error.message); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    await stopServer();
    if (session) command('tmux', ['-L', 'agent-ui', 'kill-session', '-t', '=' + session]);
    fs.rmSync(temp, { recursive: true, force: true });
});
