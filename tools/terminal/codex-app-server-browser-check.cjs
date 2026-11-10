'use strict';

// Real loopback gateway, PHP owner projection, Chromium controls and a
// deterministic local JSON-RPC App Server fixture. Not authenticated Codex.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const repo = path.resolve(__dirname, '../..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-ui-codex-app-'));
const project = path.join(temp, 'project');
const fakeBin = path.join(temp, 'bin');
const protocolLog = path.join(temp, 'protocol.jsonl');
const script = path.join(__dirname, 'local-cockpit.cjs');
let server;
let browser;

const fakeCodex = String.raw`#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const readline = require('node:readline');
const log = process.env.CODEX_FIXTURE_LOG;
const write = (value) => { process.stdout.write(JSON.stringify(value) + '\n'); };
const record = (value) => { fs.appendFileSync(log, JSON.stringify(value) + '\n'); };
const threadId = 'fixture-thread';
const turnId = 'fixture-turn';
let requestId = 40;
readline.createInterface({ input: process.stdin }).on('line', (line) => {
    let message;
    try { message = JSON.parse(line); } catch (_) { return; }
    if (message.method === 'initialize') {
        record({ method: 'initialize', clientInfo: message.params.clientInfo });
        return write({ id: message.id, result: { userAgent: 'fixture' } });
    }
    if (message.method === 'thread/start') {
        record({ method: 'thread/start', cwd: message.params.cwd, approvalPolicy: message.params.approvalPolicy, sandbox: message.params.sandbox });
        return write({ id: message.id, result: { thread: { id: threadId } } });
    }
    if (message.method === 'turn/start') {
        const prompt = message.params.input[0].text;
        record({ method: 'turn/start', prompt });
        write({ id: message.id, result: { turn: { id: turnId } } });
        write({ method: 'turn/started', params: { threadId, turn: { id: turnId, status: 'inProgress' } } });
        // Deltas and output are intentionally ignored by the UI.
        write({ method: 'item/agentMessage/delta', params: { threadId, turnId, delta: 'PRIVATE-DELTA-NOT-EXPOSED' } });
        write({ method: 'item/started', params: { threadId, turnId,
            item: { type: 'commandExecution', id: 'cmd-1', command: 'echo secret', status: 'inProgress' } } });
        if (prompt.includes('item-context')) {
            write({ method: 'item/started', params: { turnId,
                item: { type: 'commandExecution', id: 'cmd-1', command: 'UNSCOPED-COMMAND', status: 'inProgress' } } });
        }
        const id = ++requestId;
        if (prompt.includes('cross-thread')) {
            write({ id: 999, method: 'item/commandExecution/requestApproval', params: {
                threadId: 'some-other-thread', turnId, command: 'wrong thread', availableDecisions: ['accept'] } });
            write({ id: 998, method: 'item/commandExecution/requestApproval', params: {
                threadId, turnId: 'old-turn', command: 'wrong turn', availableDecisions: ['accept'] } });
        }
        if (prompt.includes('unsupported')) {
            write({ id, method: 'item/permissions/requestApproval', params: {
                threadId, turnId, reason: 'Cannot approve in this UI' } });
        } else {
            write({ id, method: 'item/commandExecution/requestApproval', params: {
                threadId, ...(!prompt.includes('stale-no-turn-id') ? { turnId } : {}),
                ...(!prompt.includes('no-context') ? { itemId: 'cmd-1' } : {}),
                ...(!prompt.includes('no-context') && !prompt.includes('item-context') ? { command: 'echo secret' } : {}),
                cwd: process.cwd(),
                reason: 'fixture asks permission', availableDecisions: prompt.includes('decline-only') ? ['decline'] : ['accept', 'decline'] } });
            if (prompt.includes('cross-thread')) {
                write({ method: 'serverRequest/resolved', params: { requestId: id } });
            }
        }
        if (prompt.includes('stale')) {
            write({ method: 'turn/completed', params: { threadId, turn: { id: turnId, status: 'interrupted' } } });
        }
        return;
    }
    if (Object.hasOwn(message, 'id') && Object.hasOwn(message, 'result')) {
        record({ method: 'decision', id: message.id, result: message.result });
        write({ method: 'serverRequest/resolved', params: { threadId, requestId: message.id } });
        write({ method: 'item/completed', params: { threadId, turnId,
            item: { id: 'cmd-1', type: 'commandExecution', status: 'completed' } } });
        write({ method: 'turn/completed', params: { threadId, turn: { id: turnId, status: 'completed' } } });
    }
});
`;

function command(executable, args) {
    const result = spawnSync(executable, args, { cwd: repo, encoding: 'utf8', timeout: 15000 });
    if (result.error) throw result.error;
    return result;
}
function wait(ms) { return new Promise((resolve) => setTimeout(resolve, ms)); }
async function until(test, description, timeoutMs = 15000) {
    const limit = Date.now() + timeoutMs;
    while (Date.now() < limit) {
        if (await test()) return;
        await wait(100);
    }
    throw new Error('Timed out: ' + description);
}
function port() {
    return new Promise((resolve, reject) => {
        const listener = net.createServer();
        listener.once('error', reject);
        listener.listen(0, '127.0.0.1', () => {
            const value = listener.address().port;
            listener.close(() => resolve(value));
        });
    });
}
function get(localPort, route) {
    return new Promise((resolve, reject) => {
        http.get({ host: '127.0.0.1', port: localPort, path: route }, (response) => {
            response.resume();
            resolve(response.statusCode);
        }).on('error', reject);
    });
}
function loggedDecisions() {
    try {
        return fs.readFileSync(protocolLog, 'utf8').split('\n').filter(Boolean)
            .map((line) => JSON.parse(line)).filter((value) => value.method === 'decision');
    } catch (_) { return []; }
}
async function run() {
    const fixture = command('php', [path.join(repo, 'tools/rendered/workflow-live-fixture.php'), project]);
    assert.equal(fixture.status, 0, fixture.stderr);
    fs.mkdirSync(fakeBin);
    fs.writeFileSync(path.join(fakeBin, 'codex'), fakeCodex, { mode: 0o755 });
    const localPort = await port();
    const origin = 'http://127.0.0.1:' + localPort;
    server = spawn(process.execPath, [script, '--root=' + project, '--task=UI-36', '--serve', '--port=' + localPort], {
        cwd: repo, stdio: ['ignore', 'pipe', 'pipe'],
        env: { ...process.env, CODEX_FIXTURE_LOG: protocolLog, PATH: fakeBin + path.delimiter + process.env.PATH },
    });
    let output = '', errors = '';
    server.stdout.on('data', (chunk) => { output += chunk; });
    server.stderr.on('data', (chunk) => { errors += chunk; });
    await until(() => {
        if (server.exitCode !== null) throw new Error('Cockpit exited: ' + errors);
        return output.includes('Normal terminal:');
    }, 'cockpit starts');

    assert.equal(await get(localPort, '/_local/codex-app-server'), 401, 'App Server state is private');
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox'],
        ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
    const page = await browser.newPage();
    await page.goto(output.match(/http:\/\/\S+/)[0]);
    await page.waitForURL('**/task/UI-36/progress');
    // The CLI-first cockpit intentionally has no structured provider panel.
    // The still-supported private JSON-RPC transport retains explicit security
    // tests; browser requests below are fixture actions, not human approval.
    assert.equal(await page.locator('[data-codex-app-server]').count(), 0);
    const snapshot = () => page.evaluate(async () => (await (await fetch('/_local/codex-app-server')).json()));
    const post = (action, payload = {}) => page.evaluate(async ({ action, payload }) => {
        const current = await (await fetch('/_local/codex-app-server')).json();
        const response = await fetch('/_local/agent-activity/codex/' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Agent-Ui-Csrf': current.csrf },
            body: JSON.stringify(payload),
        });
        return { status: response.status, body: await response.text() };
    }, { action, payload });
    const waitState = (state) => until(async () => (await snapshot()).state === state, 'Codex transport state ' + state);
    assert.equal((await snapshot()).state, 'stopped');
    const csrf = (await snapshot()).csrf;
    const noCsrf = await page.evaluate(async () => (await fetch('/_local/codex-app-server/start', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json',
            'X-Agent-Ui-Csrf': 'wrong' }, body: '{}',
    })).status);
    assert.equal(noCsrf, 403);
    const wrongOrigin = await page.request.post(origin + '/_local/codex-app-server/start', {
        headers: { Origin: 'https://attacker.example', 'Content-Type': 'application/json', 'X-Agent-Ui-Csrf': csrf },
        data: '{}',
    });
    assert.equal(wrongOrigin.status(), 403);

    assert.equal((await post('start')).status, 200, 'fixture Codex transport starts with scoped credentials');
    await waitState('idle');
    const boot = fs.readFileSync(protocolLog, 'utf8');
    assert.ok(boot.includes('"method":"initialize"'));
    assert.ok(boot.includes('"method":"thread/start"'));
    assert.ok(boot.includes('"approvalPolicy":"on-request"'));
    assert.ok(boot.includes('"sandbox":"workspace-write"'));

    assert.equal((await post('turn', { prompt: 'cross-thread approval fixture' })).status, 200);
    await until(async () => (await snapshot()).pending_requests.length === 1, 'exactly one same-thread request');
    const summary = await snapshot();
    assert.equal(summary.state, 'waiting_approval');
    assert.equal(summary.pending_requests.length, 1, 'foreign-thread request cannot become a decision');
    assert.equal(JSON.stringify(summary).includes('PRIVATE-DELTA-NOT-EXPOSED'), false, 'model text is never exposed');
    assert.equal(JSON.stringify(summary.pending_requests).includes('wrong thread'), false);
    assert.equal(JSON.stringify(summary.pending_requests).includes('wrong turn'), false);
    assert.equal(summary.pending_requests[0].can_accept, true);
    assert.equal((await post('decision', {
        token: summary.pending_requests[0].token, decision: 'accept',
    })).status, 200);
    await until(() => loggedDecisions().length === 1, 'one scoped fixture decision');
    assert.deepEqual(loggedDecisions()[0].result, { decision: 'accept' });
    await waitState('idle');
    assert.equal(await page.locator('[data-workflow-live]').count(), 1, 'owner workflow remains separate');

    assert.equal((await post('turn', { prompt: 'decline-only' })).status, 200);
    await waitState('waiting_approval');
    const restricted = await snapshot();
    assert.equal(restricted.pending_requests.length, 1);
    assert.equal(restricted.pending_requests[0].can_accept, false);
    assert.equal(restricted.pending_requests[0].can_decline, true);
    assert.equal((await post('decision', {
        token: restricted.pending_requests[0].token, decision: 'decline',
    })).status, 200);
    await until(() => loggedDecisions().length === 2, 'restricted fixture decision');
    assert.deepEqual(loggedDecisions()[1].result, { decision: 'decline' });
    await waitState('idle');

    assert.equal((await post('turn', { prompt: 'item-context' })).status, 200);
    await waitState('waiting_approval');
    const scoped = await snapshot();
    assert.equal(scoped.pending_requests.length, 1);
    assert.ok(JSON.stringify(scoped.pending_requests).includes('Command: echo secret'),
        'same-thread item supplies missing command context');
    assert.equal(JSON.stringify(scoped.pending_requests).includes('UNSCOPED-COMMAND'), false);
    assert.equal((await post('decision', {
        token: scoped.pending_requests[0].token, decision: 'decline',
    })).status, 200);
    await until(() => loggedDecisions().length === 3, 'item-context fixture decision');
    await waitState('idle');

    const turnCountBeforeStale = fs.readFileSync(protocolLog, 'utf8').split('\n')
        .filter((line) => line.includes('"method":"turn/start"')).length;
    assert.equal((await post('turn', { prompt: 'stale-turn' })).status, 200);
    await until(() => fs.readFileSync(protocolLog, 'utf8').split('\n')
        .filter((line) => line.includes('"method":"turn/start"')).length === turnCountBeforeStale + 1,
        'stale turn actually dispatched');
    await until(async () => {
        const value = await snapshot();
        return value.state === 'idle' && value.pending_requests.length === 0;
    }, 'finished turn removes stale approvals');
    assert.equal(loggedDecisions().length, 3, 'no automatic decision after completion');

    assert.equal((await post('turn', { prompt: 'stale-no-turn-id' })).status, 200);
    await until(async () => {
        const value = await snapshot();
        return value.state === 'idle' && value.pending_requests.length === 0 &&
            value.activity.some((entry) => entry.label.includes('interrupted'));
    }, 'missing-turn-id stale approval cleared');
    assert.equal(loggedDecisions().length, 3);

    assert.equal((await post('turn', { prompt: 'no-context' })).status, 200);
    await waitState('waiting_input');
    const unknown = await snapshot();
    assert.equal(unknown.pending_requests.length, 1);
    assert.equal(unknown.pending_requests[0].kind, 'unsupported');
    assert.notEqual(unknown.pending_requests[0].can_accept, true);
    assert.equal(loggedDecisions().length, 3, 'unidentified command is never auto-approved');

    assert.equal((await post('stop')).status, 200, 'explicit fixture stop completes');
    await until(async () => {
        const value = await snapshot();
        return value.state === 'stopped' && value.running === false;
    }, 'Codex process stopped');
    assert.ok(fs.readFileSync(protocolLog, 'utf8').includes('"method":"turn/start"'));
    console.log('Codex JSON-RPC authenticated browser API: CSRF, context-bound fixture decisions, stale cleanup and stop passed.');
}
run().catch((error) => { console.error(error); process.exitCode = 1; }).finally(async () => {
    if (browser) await browser.close();
    if (server && server.exitCode === null && server.signalCode === null) {
        const stopped = new Promise((resolve) => server.once('exit', resolve));
        server.kill('SIGTERM');
        await Promise.race([stopped, wait(5000)]);
        if (server.exitCode === null && server.signalCode === null) server.kill('SIGKILL');
    }
    fs.rmSync(temp, { recursive: true, force: true });
});
