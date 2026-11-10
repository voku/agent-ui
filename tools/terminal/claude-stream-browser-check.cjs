'use strict';

// Real loopback gateway, PHP owner projection and Chromium UI with a
// deterministic stream-json Claude fixture. No real Claude account is claimed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const repo = path.resolve(__dirname, '../..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-ui-claude-'));
const project = path.join(temp, 'project');
const fakeBin = path.join(temp, 'bin');
const protocolLog = path.join(temp, 'claude-input.jsonl');
let transport;
let browser;

const fixture = String.raw`#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const readline = require('node:readline');
const log = process.env.CLAUDE_FIXTURE_LOG;
fs.appendFileSync(log, JSON.stringify({ argv: process.argv.slice(2) }) + '\n');
process.stdout.write(JSON.stringify({ type: 'system', subtype: 'init', session_id: 'fixture-claude' }) + '\n');
readline.createInterface({ input: process.stdin }).on('line', (line) => {
    const input = JSON.parse(line);
    fs.appendFileSync(log, JSON.stringify(input) + '\n');
    process.stdout.write(JSON.stringify({ type: 'stream_event',
        event: { type: 'content_block_delta', delta: { type: 'text_delta', text: 'PRIVATE-CLAUDE-OUTPUT' } } }) + '\n');
    process.stdout.write(JSON.stringify({ type: 'assistant', message: { content: [
        { type: 'tool_use', name: 'Bash', input: { command: 'echo SECRET-TOOL-INPUT' } }
    ] } }) + '\n');
    setTimeout(() => {
        process.stdout.write(JSON.stringify({ type: 'user', message: { content: [
            { type: 'tool_result', tool_use_id: 'tool-1', content: 'SECRET-RESULT' }
        ] } }) + '\n');
        process.stdout.write(JSON.stringify({ type: 'result', is_error: false, result: 'PRIVATE-CLAUDE-RESULT' }) + '\n');
    }, 500);
});
`;

async function until(predicate, label, timeout = 20000) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        if (await predicate()) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error('Timed out: ' + label);
}
function freePort() {
    return new Promise((resolve, reject) => {
        const socket = net.createServer();
        socket.once('error', reject);
        socket.listen(0, '127.0.0.1', () => {
            const port = socket.address().port;
            socket.close(() => resolve(port));
        });
    });
}

async function run() {
    const setup = spawnSync('php', [path.join(repo, 'tools/rendered/workflow-live-fixture.php'), project],
        { cwd: repo, encoding: 'utf8', timeout: 10000 });
    assert.equal(setup.status, 0, setup.stderr);
    fs.mkdirSync(fakeBin);
    fs.writeFileSync(path.join(fakeBin, 'claude'), fixture, { mode: 0o755 });
    const port = await freePort();
    transport = spawn(process.execPath, [path.join(repo, 'tools/terminal/local-cockpit.cjs'),
        '--root=' + project, '--task=UI-36', '--serve', '--port=' + port], {
        cwd: repo, stdio: ['ignore', 'pipe', 'pipe'],
        env: { ...process.env, CLAUDE_FIXTURE_LOG: protocolLog, PATH: fakeBin + path.delimiter + process.env.PATH },
    });
    let stdout = '', stderr = '';
    transport.stdout.on('data', (data) => { stdout += data; });
    transport.stderr.on('data', (data) => { stderr += data; });
    await until(() => {
        if (transport.exitCode !== null) throw new Error('Gateway died: ' + stderr);
        return stdout.includes('Normal terminal:');
    }, 'gateway ready');
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox'],
        ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    const page = await browser.newPage();
    await page.goto(stdout.match(/http:\/\/\S+/)[0]);
    await page.waitForURL('**/task/UI-36/progress');
    // No structured provider panel in the CLI-first workbench. The private
    // transport remains active; test its authorization and bounded output
    // without reintroducing a duplicate browser approval surface.
    assert.equal(await page.locator('[data-agent-activity]').count(), 0);
    const pageErrors = [];
    page.on('pageerror', (error) => { pageErrors.push(error.message); });
    const snapshot = () => page.evaluate(async () => (await (await fetch('/_local/agent-activity/claude')).json()));
    const post = (action, payload = {}) => page.evaluate(async ({ action, payload }) => {
        const current = await (await fetch('/_local/agent-activity/claude')).json();
        const response = await fetch('/_local/agent-activity/claude/' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Agent-Ui-Csrf': current.csrf },
            body: JSON.stringify(payload),
        });
        return { status: response.status, body: await response.text() };
    }, { action, payload });
    const before = await snapshot();
    assert.equal(before.state, 'stopped');
    assert.equal(before.provider, 'claude');
    assert.equal(before.supports_decisions, false);
    const invalid = await page.evaluate(async () => (await fetch('/_local/agent-activity/claude/start', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Agent-Ui-Csrf': 'incorrect' },
        body: '{}',
    })).status);
    assert.equal(invalid, 403, 'Claude start requires valid CSRF token');

    assert.equal((await post('start')).status, 200, 'fixture Claude stream transport starts');
    await until(async () => (await snapshot()).state === 'idle', 'Claude stream starts', 15000);
    await until(() => fs.existsSync(protocolLog), 'Claude fixture was launched');
    const initial = JSON.parse(fs.readFileSync(protocolLog, 'utf8').trim().split('\n')[0]);
    for (const flag of ['-p', '--input-format', 'stream-json', '--output-format', '--verbose',
        '--permission-mode', 'default', '--permission-prompts', 'none']) {
        assert.ok(initial.argv.includes(flag), 'required CLI flag: ' + flag);
    }
    assert.equal((await post('decision', { token: 'invented', decision: 'accept' })).status, 409,
        'Claude tool approvals cannot be fabricated through browser JSON');
    assert.equal((await post('turn', { prompt: 'Please inspect the owner-backed task' })).status, 200);
    await until(() => fs.readFileSync(protocolLog, 'utf8').includes('"type":"user"'),
        'Claude prompt delivered over stream-json');
    const messages = fs.readFileSync(protocolLog, 'utf8').trim().split('\n').map((line) => JSON.parse(line));
    assert.equal(messages[1].message.content[0].text, 'Please inspect the owner-backed task');
    await until(async () => (await snapshot()).activity.some((entry) => entry.label.includes('Claude completed a turn')),
        'bounded Claude tool and result events');
    const after = await snapshot();
    assert.equal(after.state, 'idle');
    assert.ok(after.activity.some((entry) => entry.label.includes('requested tool Bash')));
    assert.equal(after.pending_requests.length, 0);
    const publicText = JSON.stringify(after);
    for (const secret of ['SECRET-TOOL-INPUT', 'PRIVATE-CLAUDE-OUTPUT', 'PRIVATE-CLAUDE-RESULT', 'SECRET-RESULT']) {
        assert.equal(publicText.includes(secret), false, 'no model/tool payload leak: ' + secret);
    }
    assert.equal(await page.locator('[data-workflow-live]').count(), 1, 'Loop remains independent');

    assert.equal((await post('stop')).status, 200, 'explicit fixture stop');
    await until(async () => (await snapshot()).state === 'stopped', 'Claude transport stops');
    assert.deepEqual(pageErrors, []);
    console.log('Claude stream-json authenticated browser API: CSRF, prompts, bounded events, denied tool approvals and stop passed.');
}
run().catch((error) => { console.error(error); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    if (transport && transport.exitCode === null && transport.signalCode === null) {
        const exit = new Promise((resolve) => transport.once('exit', resolve));
        transport.kill('SIGTERM');
        await Promise.race([exit, new Promise((resolve) => setTimeout(resolve, 5000))]);
        if (transport.exitCode === null && transport.signalCode === null) transport.kill('SIGKILL');
    }
    fs.rmSync(temp, { recursive: true, force: true });
});
