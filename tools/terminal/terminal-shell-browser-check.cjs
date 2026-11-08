'use strict';

// End-to-end browser smoke: a real interactive shell can run an arbitrary
// installed CLI while preserving the task context and tmux process on reload.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const repo = path.resolve(__dirname, '../..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-ui-shell-'));
const project = path.join(temp, 'project');
const script = path.join(__dirname, 'local-cockpit.cjs');
let transport;
let browser;
let session;

function command(name, args, env = process.env) {
    const result = spawnSync(name, args, { cwd: repo, encoding: 'utf8', timeout: 15000, env });
    if (result.error) throw result.error;
    return result;
}
function tmux(args) {
    return command('tmux', ['-L', 'agent-ui', ...args]);
}
function pane(text) {
    return tmux(['capture-pane', '-p', '-t', '=' + session + ':']).stdout.includes(text);
}
function pid() {
    const result = tmux(['display-message', '-p', '-t', '=' + session + ':', '#{pane_pid}']);
    assert.equal(result.status, 0);
    return result.stdout.trim();
}
async function until(test, label, timeoutMs = 20000) {
    const end = Date.now() + timeoutMs;
    while (Date.now() < end) {
        if (await test()) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error('Timed out: ' + label);
}
function freePort() {
    return new Promise((resolve, reject) => {
        const listener = net.createServer();
        listener.once('error', reject);
        listener.listen(0, '127.0.0.1', () => {
            const port = listener.address().port;
            listener.close(() => resolve(port));
        });
    });
}
async function stopTransport() {
    if (!transport || transport.exitCode !== null || transport.signalCode !== null) return;
    const stopped = new Promise((resolve) => transport.once('exit', resolve));
    transport.kill('SIGTERM');
    await Promise.race([stopped, new Promise((_, reject) => setTimeout(() => reject(new Error('Transport shutdown timed out')), 7000))]);
}

async function run() {
    const fixture = command('php', [path.join(repo, 'tools/rendered/workflow-live-fixture.php'), project]);
    assert.equal(fixture.status, 0, fixture.stderr);
    fs.mkdirSync(path.join(project, 'bin'));
    fs.writeFileSync(path.join(project, 'bin/agy'),
        '#!/bin/sh\nprintf "GENERIC_CLI_TASK=%s\\n" "$AGENT_LOOP_TASK_ID"\n', { mode: 0o755 });

    const port = await freePort();
    transport = spawn(process.execPath, [script, '--root=' + project, '--task=UI-36', '--serve', '--port=' + port],
        { cwd: repo, stdio: ['ignore', 'pipe', 'pipe'] });
    let output = '';
    let errors = '';
    transport.stdout.on('data', (chunk) => { output += chunk; });
    transport.stderr.on('data', (chunk) => { errors += chunk; });
    await until(() => {
        if (transport.exitCode !== null) throw new Error('Transport exited: ' + errors);
        return output.includes('Normal terminal:');
    }, 'cockpit startup');
    session = output.match(/tmux session ([^;]+)/)[1];
    const accessUrl = output.match(/http:\/\/\S+/)[0];

    browser = await chromium.launch({ headless: true, args: ['--no-sandbox'],
        ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    const page = await browser.newPage({ viewport: { width: 1500, height: 920 } });
    await page.goto(accessUrl);
    await page.waitForURL('**/task/UI-36/progress');
    await page.locator('[data-terminal-host]').selectOption('shell');
    assert.equal(tmux(['has-session', '-t', '=' + session]).status, 1, 'viewing does not start a shell');
    await page.locator('[data-terminal-start-button]').click();
    await until(() => tmux(['has-session', '-t', '=' + session]).status === 0, 'browser starts generic shell');
    const originalPid = pid();
    await page.locator('[data-terminal-state]').getByText('tmux session running (shell)').waitFor();
    const frame = page.frameLocator('[data-terminal-frame]');
    await frame.locator('.xterm-helper-textarea').waitFor({ timeout: 15000 });
    await frame.locator('.xterm-helper-textarea').focus();
    await page.keyboard.type('echo TASK_FROM_SHELL=$AGENT_LOOP_TASK_ID');
    await page.keyboard.press('Enter');
    await until(() => pane('TASK_FROM_SHELL=UI-36'), 'task environment');

    await page.keyboard.type('pwd');
    await page.keyboard.press('Enter');
    await until(() => pane(project), 'project working directory');

    // agy stands in for any future CLI. No provider integration or special
    // agent-ui executable allowlist is involved in invoking it from the shell.
    await page.keyboard.type('./bin/agy');
    await page.keyboard.press('Enter');
    await until(() => pane('GENERIC_CLI_TASK=UI-36'), 'arbitrary CLI inside shell');

    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNQaLj/HwAEwgJ/1iyXeQAAAABJRU5ErkJggg==', 'base64');
    const csrf = await page.evaluate(async () => (await (await fetch('/_local/session')).json()).csrf);
    const noCsrf = await page.evaluate(async (bytes) => (await fetch('/_local/attachment', {
        method: 'POST', headers: { 'Content-Type': 'image/png', 'X-Agent-Ui-Csrf': 'wrong' },
        body: Uint8Array.from(bytes),
    })).status, [...png]);
    assert.equal(noCsrf, 403, 'image upload requires the session CSRF token');
    const malformed = await page.evaluate(async ({ token }) => (await fetch('/_local/attachment', {
        method: 'POST', headers: { 'Content-Type': 'image/png', 'X-Agent-Ui-Csrf': token },
        body: new Blob(['not actually PNG'], { type: 'image/png' }),
    })).status, { token: csrf });
    assert.equal(malformed, 415, 'fake image payload is not accepted');

    await page.locator('[data-terminal-image-file]').setInputFiles({
        name: 'screenshot.png', mimeType: 'image/png', buffer: png,
    });
    await until(async () => (await page.locator('[data-terminal-message]').textContent()).includes('Image path pasted'), 'file upload');
    const firstMessage = await page.locator('[data-terminal-message]').textContent();
    const imagePath = firstMessage.split('Image path pasted (not submitted): ')[1].trim();
    assert.ok(fs.existsSync(imagePath), 'uploaded image is available to the running agent');
    assert.deepEqual(fs.readFileSync(imagePath), png, 'image bytes are retained');
    await until(() => tmux(['capture-pane', '-p', '-J', '-t', '=' + session + ':']).stdout.includes(imagePath),
        'absolute image path pasted into terminal without pressing Enter');

    // Clipboard access is a user-gesture-only browser API. Chromium grants it
    // to the local test origin, then the button uses the same upload path.
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write'],
        { origin: new URL(accessUrl).origin });
    await page.evaluate(async (encoded) => {
        const raw = atob(encoded);
        const bytes = Uint8Array.from(raw, (char) => char.charCodeAt(0));
        await navigator.clipboard.write([new ClipboardItem({
            'image/png': new Blob([bytes], { type: 'image/png' }),
        })]);
    }, png.toString('base64'));
    await page.locator('[data-terminal-image-clipboard]').click();
    await until(async () => {
        const text = await page.locator('[data-terminal-message]').textContent();
        return text.includes('Image path pasted') && text !== firstMessage;
    }, 'clipboard paste upload');
    const clipboardMessage = await page.locator('[data-terminal-message]').textContent();
    const clipboardPath = clipboardMessage.split('Image path pasted (not submitted): ')[1].trim();
    assert.ok(fs.existsSync(clipboardPath), 'clipboard image saved to private task storage');

    await page.reload();
    await frame.locator('.xterm-helper-textarea').waitFor({ timeout: 15000 });
    assert.equal(pid(), originalPid, 'reloading must not restart the shell');
    await page.locator('[data-terminal-toggle]').click();
    assert.equal(pid(), originalPid, 'disconnecting must not stop the shell');
    await page.locator('[data-terminal-toggle]').click();
    await frame.locator('.xterm-helper-textarea').waitFor({ timeout: 15000 });
    assert.equal(pid(), originalPid, 'reconnecting must not restart shell');

    const status = command(process.execPath, [script, '--root=' + project, '--task=UI-36', '--status', '--format=json']);
    assert.equal(status.status, 0, status.stderr);
    assert.deepEqual(JSON.parse(status.stdout), { state: 'session_running', host: 'shell' });

    // Hold an authenticated upload open while the user stops and starts a
    // different process for the same task. The old upload must never type
    // into the replacement process.
    const origin = new URL(accessUrl).origin;
    const cookies = await page.context().cookies(origin);
    const cookie = cookies.map((entry) => `${entry.name}=${entry.value}`).join('; ');
    const pending = http.request({ hostname: '127.0.0.1', port, path: '/_local/attachment', method: 'POST', headers: {
        Cookie: cookie, Origin: origin, 'Content-Type': 'image/png', 'Content-Length': png.length, 'X-Agent-Ui-Csrf': csrf,
    } });
    const pendingStatus = new Promise((resolve, reject) => {
        pending.on('response', (response) => { response.resume(); response.on('end', () => resolve(response.statusCode)); });
        pending.on('error', reject);
    });
    pending.write(png.subarray(0, 8));
    await until(async () => (await page.evaluate(async ({ token, bytes }) => (await fetch('/_local/attachment', {
        method: 'POST', headers: { 'Content-Type': 'image/png', 'X-Agent-Ui-Csrf': token }, body: Uint8Array.from(bytes),
    })).status, { token: csrf, bytes: [...png] })) === 409, 'first upload is in flight');

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('[data-terminal-stop]').click();
    await until(() => tmux(['has-session', '-t', '=' + session]).status !== 0, 'browser stops generic shell');
    assert.equal(fs.existsSync(imagePath), false, 'stopping the task cleans uploaded image');
    assert.equal(fs.existsSync(clipboardPath), false, 'stopping the task cleans pasted clipboard image');
    await page.locator('[data-terminal-start-button]').click();
    await until(() => tmux(['has-session', '-t', '=' + session]).status === 0 && pid() !== originalPid, 'new task session');
    pending.end(png.subarray(8));
    assert.equal(await pendingStatus, 409, 'an upload cannot target a replacement session');
    assert.equal(pane('agent-ui-attachments-'), false, 'stale upload does not type into the new process');

    const changedProgram = http.request({ hostname: '127.0.0.1', port, path: '/_local/attachment', method: 'POST', headers: {
        Cookie: cookie, Origin: origin, 'Content-Type': 'image/png', 'Content-Length': png.length, 'X-Agent-Ui-Csrf': csrf,
    } });
    const changedProgramStatus = new Promise((resolve, reject) => {
        changedProgram.on('response', (response) => { response.resume(); response.on('end', () => resolve(response.statusCode)); });
        changedProgram.on('error', reject);
    });
    changedProgram.write(png.subarray(0, 8));
    await until(async () => (await page.evaluate(async ({ token, bytes }) => (await fetch('/_local/attachment', {
        method: 'POST', headers: { 'Content-Type': 'image/png', 'X-Agent-Ui-Csrf': token }, body: Uint8Array.from(bytes),
    })).status, { token: csrf, bytes: [...png] })) === 409, 'second upload is in flight');
    assert.equal(tmux(['send-keys', '-t', '=' + session + ':', 'sleep 30', 'Enter']).status, 0);
    await until(() => tmux(['display-message', '-p', '-t', '=' + session + ':', '#{pane_current_command}']).stdout.trim() === 'sleep', 'foreground program changed');
    changedProgram.end(png.subarray(8));
    assert.equal(await changedProgramStatus, 409, 'an upload cannot target a different foreground program');
    tmux(['send-keys', '-t', '=' + session + ':', 'C-c']);
    await until(() => tmux(['display-message', '-p', '-t', '=' + session + ':', '#{pane_current_command}']).stdout.trim() !== 'sleep', 'shell resumed');

    const uploaded = await page.evaluate(async ({ token, bytes }) => {
        const result = await fetch('/_local/attachment', {
            method: 'POST', headers: { 'Content-Type': 'image/png', 'X-Agent-Ui-Csrf': token }, body: Uint8Array.from(bytes),
        });
        return { status: result.status, body: await result.json() };
    }, { token: csrf, bytes: [...png] });
    assert.equal(uploaded.status, 201);
    assert.ok(fs.existsSync(uploaded.body.path));
    assert.equal(tmux(['kill-session', '-t', '=' + session]).status, 0, 'simulate the host exiting itself');
    const alternateTemp = path.join(temp, 'alternate-temp');
    fs.mkdirSync(alternateTemp);
    const fakeTools = path.join(temp, 'fake-tools');
    fs.mkdirSync(fakeTools);
    fs.writeFileSync(path.join(fakeTools, 'tmux'), '#!/bin/sh\nprintf "error connecting to socket (Permission denied)\\n" >&2\nexit 1\n', { mode: 0o755 });
    const unknownStatus = command(process.execPath, [script, '--root=' + project, '--task=UI-36', '--stop'], {
        ...process.env, PATH: fakeTools + path.delimiter + process.env.PATH, TMPDIR: alternateTemp,
    });
    assert.notEqual(unknownStatus.status, 0, 'unknown tmux failures are not treated as a missing session');
    assert.ok(fs.existsSync(uploaded.body.path), 'an unavailable session does not authorize image cleanup');
    const attachmentDir = path.dirname(uploaded.body.path);
    const movedDir = path.join(temp, 'untrusted-target');
    fs.renameSync(attachmentDir, movedDir);
    fs.symlinkSync(movedDir, attachmentDir);
    const rejectedCleanup = command(process.execPath, [script, '--root=' + project, '--task=UI-36', '--stop'], { ...process.env, TMPDIR: alternateTemp });
    assert.notEqual(rejectedCleanup.status, 0, 'cleanup rejects a substituted symlink');
    assert.ok(fs.existsSync(path.join(movedDir, path.basename(uploaded.body.path))), 'cleanup does not delete a symlink target');
    fs.unlinkSync(attachmentDir);
    fs.renameSync(movedDir, attachmentDir);
    const cleanup = command(process.execPath, [script, '--root=' + project, '--task=UI-36', '--stop'], { ...process.env, TMPDIR: alternateTemp });
    assert.equal(cleanup.status, 0, 'explicit cleanup works after host exit, even when TMPDIR changes');
    assert.equal(fs.existsSync(uploaded.body.path), false, 'cleanup removes images after host exit');
    console.log('Generic shell, arbitrary CLI, authenticated image upload, clipboard, reconnect and stop passed.');
}

run().catch((error) => { console.error(error); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    await stopTransport();
    if (session) tmux(['kill-session', '-t', '=' + session]);
    fs.rmSync(temp, { recursive: true, force: true });
});
