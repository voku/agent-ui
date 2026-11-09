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
    const panel = page.locator('[data-agent-activity]');
    await panel.locator('[data-agent-activity-provider]').selectOption('claude');
    await panel.locator('[data-codex-app-server-state]').getByText('stopped', { exact: false }).waitFor();
    const snapshotBefore = await page.evaluate(async () =>
        (await (await fetch('/_local/agent-activity/claude')).json()));
    assert.equal(snapshotBefore.provider, 'claude');
    assert.equal(snapshotBefore.supports_decisions, false);
    const invalid = await page.evaluate(async () => (await fetch('/_local/agent-activity/claude/start', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json',
            'X-Agent-Ui-Csrf': 'incorrect' }, body: '{}',
    })).status);
    assert.equal(invalid, 403, 'Claude start is CSRF protected');
    await panel.locator('[data-codex-app-server-start]').click();
    await panel.locator('[data-codex-app-server-state]').getByText('idle', { exact: false }).waitFor();
    await until(() => fs.existsSync(protocolLog), 'Claude structured process launched');
    const initial = JSON.parse(fs.readFileSync(protocolLog, 'utf8').trim().split('\n')[0]);
    for (const flag of ['-p', '--input-format', 'stream-json', '--output-format', '--verbose',
        '--permission-mode', 'default', '--permission-prompts', 'none']) {
        assert.ok(initial.argv.includes(flag), 'required documented CLI flag: ' + flag);
    }
    const unrelatedDecision = await page.evaluate(async (csrf) => (await fetch('/_local/agent-activity/claude/decision', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json',
            'X-Agent-Ui-Csrf': csrf }, body: JSON.stringify({ token: 'invented', decision: 'accept' }),
    })).status, snapshotBefore.csrf);
    assert.equal(unrelatedDecision, 409, 'Claude tool approvals cannot be fabricated through browser JSON');
    await panel.locator('[data-codex-app-server-prompt]').fill('Please inspect the owner-backed task');
    await panel.locator('[data-codex-app-server-send]').click();
    await until(() => fs.readFileSync(protocolLog, 'utf8').includes('"type":"user"'), 'prompt delivered over stream-json');
    const messages = fs.readFileSync(protocolLog, 'utf8').trim().split('\n').map((line) => JSON.parse(line));
    assert.equal(messages[1].message.content[0].text, 'Please inspect the owner-backed task');
    await until(async () => (await panel.locator('[data-codex-app-server-activity]').textContent())
        .includes('Claude completed a turn'), 'observed tool + result events appear in shared UI');
    const after = await page.evaluate(async () => (await (await fetch('/_local/agent-activity/claude')).json()));
    assert.equal(after.state, 'idle');
    assert.ok(after.activity.some((entry) => entry.label.includes('requested tool Bash')));
    assert.equal(after.pending_requests.length, 0);
    const publicText = JSON.stringify(after);
    for (const secret of ['SECRET-TOOL-INPUT', 'PRIVATE-CLAUDE-OUTPUT', 'PRIVATE-CLAUDE-RESULT', 'SECRET-RESULT']) {
        assert.equal(publicText.includes(secret), false, 'no model/tool payload leak: ' + secret);
    }
    assert.equal(await page.locator('[data-workflow-live]').count(), 1, 'Loop visualization remains separate');
    await panel.locator('[data-codex-app-server-stop]').click();
    await panel.locator('[data-codex-app-server-state]').getByText('stopped', { exact: false }).waitFor();
    console.log('Claude structured CLI browser: provider sharing, CSRF, prompts, events, bounded observation, explicit denied approvals, stop passed.');
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
