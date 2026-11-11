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
const startsFile = path.join(temp, 'fake-host-starts');
let transportLock;
fs.mkdirSync(fakeBin);
const quotedPidFile = "'" + pidFile.replace(/'/g, "'\\''") + "'";
const quotedStartsFile = "'" + startsFile.replace(/'/g, "'\\''") + "'";
fs.writeFileSync(path.join(fakeBin, 'codex'), '#!/bin/sh\nprintf "%s" "$$" > ' + quotedPidFile + '\nprintf "%s\\n" "$$" >> ' + quotedStartsFile + '\nprintf "FAKE HOST READY\\n"\nwhile IFS= read -r line; do printf "received: %s\\n" "$line"; done\n', { mode: 0o755 });
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
    if (transportLock) await until(() => !fs.existsSync(transportLock), 'transport lock cleanup');
}
async function run() {
    check(command('php', [path.join(packageRoot, 'tools/rendered/workflow-live-fixture.php'), project]), 'Fixture');
    const port = await freePort();
    const origin = `http://127.0.0.1:${port}`;
    const script = path.join(__dirname, 'local-cockpit.cjs');
    const cli = path.join(packageRoot, 'bin/agent-ui');
    const baseArgs = [script, `--root=${project}`, '--task=UI-36'];
    const browserStart = process.env.COCKPIT_TEST_BROWSER_START === '1';
    const useCli = process.env.COCKPIT_TEST_USE_CLI === '1';
    const sessionAction = browserStart ? 'serve' : 'start';
    const cliArgs = [cli, 'session', sessionAction, 'UI-36', `--root=${project}`, ...(browserStart ? [] : ['--host=codex']), `--port=${port}`];
    server = spawn(useCli ? 'php' : process.execPath, useCli ? cliArgs : [...baseArgs, browserStart ? '--serve' : '--start=codex', `--port=${port}`], { cwd: packageRoot, env, stdio: ['ignore', 'pipe', 'pipe'] });
    let output = '', errors = '';
    server.stdout.on('data', (chunk) => { output += chunk; });
    server.stderr.on('data', (chunk) => { errors += chunk; });
    await until(() => {
        if (server.exitCode !== null) throw new Error('Transport failed to start: ' + errors);
        return output.includes('Normal terminal:');
    }, 'transport startup');
    const accessUrl = output.match(/http:\/\/\S+/)[0];
    session = output.match(/tmux session ([^;]+)/)[1];
    const lockId = crypto.createHash('sha256').update(fs.realpathSync(project) + '\0UI-36').digest('hex').slice(0, 24);
    transportLock = path.join(fs.realpathSync('/tmp'), `agent-ui-terminal-${process.getuid()}-${lockId}`);
    if (!browserStart) await until(() => fs.existsSync(pidFile), 'fake host startup');
    else assert.equal(fs.existsSync(pidFile), false, 'opening the cockpit does not start a host');
    let initialPid = browserStart ? null : fs.readFileSync(pidFile, 'utf8');

    assert.equal((await request(port, '/task/UI-36/progress')).status, 401);
    assert.equal((await request(port, '/_local/access', { Host: `attacker.example:${port}` })).status, 403);
    assert.equal((await request(port, '/_local/login', { Origin: 'https://attacker.example', 'Content-Type': 'application/json' }, 'POST', '{}')).status, 403);
    const duplicate = command(process.execPath, [...baseArgs, '--start=codex', `--port=${await freePort()}`]);
    assert.notEqual(duplicate.status, 0);
    if (initialPid) assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid);

    browser = await chromium.launch({ headless: true, ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}), args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
    const page = await context.newPage();
    await page.goto(accessUrl);
    await page.waitForURL('**/task/UI-36/progress');
    // Prompts live in the workspace's Prompts panel; without JS it links to the full workbench.
    assert.equal(await page.locator('[data-workspace-prompts] a[href="/task/UI-36/prompts"]').count(), 1);
    assert.equal(await page.locator('[data-agent-activity]').count(), 0);
    const prompts = await context.newPage();
    await prompts.addInitScript(() => {
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: (value) => { window.__copiedPrompt = value; return Promise.resolve(); } } });
    });
    await prompts.goto(origin + '/prompts');
    await prompts.locator('#task-id').fill('UI-36');
    await prompts.locator('#goal').fill('Inspect the current task');
    await prompts.locator('input[name="recipe"][value="discovery-first"]').check();
    await prompts.locator('#workbench-prompt').waitFor();
    const promptText = (await prompts.locator('#workbench-prompt').textContent()).trim();
    await prompts.locator('[data-copy-target="workbench-prompt"]').click();
    assert.equal(await prompts.evaluate(() => window.__copiedPrompt), promptText);
    await prompts.close();
    const cookies = await context.cookies(origin);
    const cookie = cookies.map((entry) => `${entry.name}=${entry.value}`).join('; ');
    const terminalUrl = new URL(await page.locator('[data-terminal-frame]').getAttribute('data-terminal-url'));
    const terminalPort = Number(terminalUrl.port);
    assert.notEqual(terminalPort, port, 'terminal has a separate browser origin');
    const sessionResponse = await request(port, '/_local/session', { Cookie: cookie });
    assert.equal(sessionResponse.status, 200);
    const injectedHeader = await request(port, '/task/UI-36/progress', {
        Cookie: cookie, 'X-Agent-Ui-Internal-Secret': 'attacker-controlled',
    });
    assert.equal(injectedHeader.status, 200, 'browser cannot override the private gateway credential');
    assert.ok(injectedHeader.body.includes('Workspace'));
    assert.equal(injectedHeader.headers['x-agent-ui-internal-secret'], undefined);

    const csrf = JSON.parse(sessionResponse.body).csrf;
    const actionHeaders = { Cookie: cookie, Origin: origin, 'Content-Type': 'application/json', 'X-Agent-Ui-Csrf': csrf };
    assert.equal((await request(port, '/_local/session/start', { ...actionHeaders, 'X-Agent-Ui-Csrf': 'wrong' }, 'POST', '{"host":"codex"}')).status, 403);
    assert.equal((await request(port, '/_local/session/start', { ...actionHeaders, Origin: 'https://attacker.example' }, 'POST', '{"host":"codex"}')).status, 403);
    assert.equal((await request(port, '/_local/session/start', actionHeaders, 'POST', '{"host":"sh"}')).status, 400);
    assert.equal((await request(port, '/_local/session/stop', { ...actionHeaders, 'X-Agent-Ui-Csrf': 'wrong' }, 'POST', '{}')).status, 403);
    assert.equal((await request(port, '/_local/session/stop', { ...actionHeaders, Origin: 'https://attacker.example' }, 'POST', '{}')).status, 403);
    if (browserStart) {
        assert.equal(fs.existsSync(pidFile), false, 'rejected requests do not start a host');
        const attempts = await Promise.all([
            page.locator('[data-terminal-start-button]').click(),
            request(port, '/_local/session/start', actionHeaders, 'POST', '{"host":"codex"}'),
            request(port, '/_local/session/start', actionHeaders, 'POST', '{"host":"codex"}'),
        ]);
        assert.deepEqual(attempts.slice(1).map((item) => item.status), [200, 200]);
        await until(() => fs.existsSync(startsFile), 'browser host startup');
        initialPid = fs.readFileSync(pidFile, 'utf8');
        assert.equal(fs.readFileSync(startsFile, 'utf8').trim().split('\n').length, 1, 'concurrent starts launch one process');
        assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid, 'concurrent starts reuse the host');
    }
    const cliStatus = useCli ? command('php', [cli, 'session', 'status', 'UI-36', `--root=${project}`, '--format=json'])
        : command(process.execPath, [...baseArgs, '--status', '--format=json']);
    check(cliStatus, 'CLI status');
    assert.deepEqual(JSON.parse(cliStatus.stdout), { state: 'session_running', host: 'codex' });
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
    const [workBox, terminalBox] = await Promise.all([page.locator('[data-workspace-work]').boundingBox(), page.locator('[data-terminal-panel]').boundingBox()]);
    assert.ok(terminalBox.x >= workBox.x + workBox.width, 'desktop workflow and terminal appear side by side');
    // One browser page provides the owner-backed briefing, human decision and
    // interactive PTY without inventing a second workflow authority.
    assert.equal(await page.locator('[data-workflow-brief]').count(), 1);
    assert.equal(await page.locator('[data-workflow-brief]').getByText('Contract authority').count(), 1);
    assert.equal(await page.locator('[data-workflow-decision] form[action$="/approve"] button[type="submit"]').count(), 1);
    assert.equal(await page.locator('[data-workflow-brief] a[href="#workflow-terminal"]').count(), 1);
    const briefBounds = await page.locator('[data-workflow-brief]').boundingBox();
    assert.ok(briefBounds.x + briefBounds.width <= terminalBox.x + 2, 'HTML briefing remains beside the terminal');
    await frame.locator('.xterm-helper-textarea').focus();
    await page.keyboard.type('browser terminal proof');
    await page.keyboard.press('Enter');
    await until(() => command('tmux', ['-L', 'agent-ui', 'capture-pane', '-p', '-t', '=' + session + ':']).stdout.includes('received: browser terminal proof'), 'terminal input');

    // Deliberate human decision through the normal CSRF-protected server route:
    // update the owner-backed HTML and graph without reloading the ttyd frame
    // or starting another host process.
    await page.evaluate(() => { window.__workbenchMarker = 'same-document'; });
    const oldBrief = await page.locator('[data-workflow-brief]').getAttribute('data-brief-digest');
    await page.locator('[data-workflow-approval-form] input[name="actor"]').fill('browser-reviewer');
    await page.locator('[data-workflow-approval-form] button[type="submit"]').click();
    await page.waitForFunction((digest) => {
        const brief = document.querySelector('[data-workflow-brief]');
        return brief && brief.getAttribute('data-brief-digest') !== digest &&
            brief.textContent.includes('Approved revision 1');
    }, oldBrief, { timeout: 15000 });
    assert.equal(await page.evaluate(() => window.__workbenchMarker), 'same-document');
    assert.equal(await page.locator('[data-workflow-approval-form]').count(), 0);
    assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid, 'HTML approval never restarts the coding host');
    assert.equal(await page.locator('[data-terminal-frame]').getAttribute('src'), terminalUrl.origin + '/_terminal/',
        'HTML approval keeps the terminal connected');
    if (process.env.WORKBENCH_SCREENSHOT) {
        await page.locator('[data-workflow-brief]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: process.env.WORKBENCH_SCREENSHOT, animations: 'disabled' });
    }

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

    if (browserStart) {
        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('[data-terminal-stop]').click();
    } else {
        check(useCli ? command('php', [cli, 'session', 'stop', 'UI-36', `--root=${project}`]) : command(process.execPath, [...baseArgs, '--stop']), 'CLI stop');
    }
    await until(async () => JSON.parse((await request(port, '/_local/session', { Cookie: cookie })).body).state === 'session_not_found', 'session exit');
    await stopServer();
    const attach = command(process.execPath, [...baseArgs, '--attach', `--port=${await freePort()}`]);
    assert.notEqual(attach.status, 0, 'attach does not restart an exited host');
    assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid);
    if (browserStart) {
        // A failed terminal transport must not take the workflow UI down.
        fs.writeFileSync(path.join(fakeBin, 'ttyd'), '#!/bin/sh\nexit 1\n', { mode: 0o755 });
        const unavailablePort = await freePort();
        server = spawn(useCli ? 'php' : process.execPath, useCli
            ? [cli, 'session', 'serve', 'UI-36', `--root=${project}`, `--port=${unavailablePort}`]
            : [...baseArgs, '--serve', `--port=${unavailablePort}`], { cwd: packageRoot, env, stdio: ['ignore', 'pipe', 'pipe'] });
        let unavailableOutput = '';
        server.stdout.on('data', (chunk) => { unavailableOutput += chunk; });
        await until(() => unavailableOutput.includes('Terminal transport unavailable'), 'unavailable terminal');
        await page.goto(unavailableOutput.match(/http:\/\/\S+/)[0]);
        await page.waitForURL('**/task/UI-36/progress');
        await page.locator('[data-terminal-state]').getByText('terminal transport unavailable').waitFor();
        assert.equal(await page.locator('[data-terminal-start-button]').isDisabled(), true);
        assert.equal((await page.request.get(`http://127.0.0.1:${unavailablePort}/task/UI-36/progress.json`)).status(), 200);
        assert.equal(fs.readFileSync(pidFile, 'utf8'), initialPid);
        await stopServer();
    }
    if (useCli) {
        fs.rmSync(path.join(fakeBin, 'ttyd'), { force: true });
        const restartPort = await freePort();
        server = spawn('php', [cli, 'session', 'start', 'UI-36', `--root=${project}`, '--host=codex', `--port=${restartPort}`], { cwd: packageRoot, env, stdio: ['ignore', 'pipe', 'pipe'] });
        let restartOutput = '';
        server.stdout.on('data', (chunk) => { restartOutput += chunk; });
        await until(() => restartOutput.includes('Normal terminal:'), 'CLI transport restart');
        await until(() => fs.readFileSync(startsFile, 'utf8').trim().split('\n').length === 2, 'second explicit host start');
        const restartedPid = fs.readFileSync(pidFile, 'utf8');
        await stopServer();
        assert.equal(command('tmux', ['-L', 'agent-ui', 'has-session', '-t', '=' + session]).status, 0, 'ending the CLI transport leaves the host running');
        assert.equal(fs.readFileSync(pidFile, 'utf8'), restartedPid);
        check(command('php', [cli, 'session', 'stop', 'UI-36', `--root=${project}`]), 'CLI stop after transport exit');
    }
    console.log('Terminal browser, reconnect, task binding, origin, authentication and single-viewer checks passed.');
}
run().catch((error) => { console.error(error.message); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    await stopServer();
    if (session) command('tmux', ['-L', 'agent-ui', 'kill-session', '-t', '=' + session]);
    fs.rmSync(temp, { recursive: true, force: true });
});
