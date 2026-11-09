'use strict';

// Real PHP backend regression: an ephemeral loopback port is not an auth
// boundary. An attacker who bypasses the Node gateway must never reach the
// task data or the human-decision API while the cockpit is running.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');

const packageRoot = path.resolve(__dirname, '../..');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-ui-backend-'));
const project = path.join(temp, 'project');
const secret = crypto.randomBytes(32).toString('base64url');
let server = null;

function freePort() {
    return new Promise((resolve, reject) => {
        const probe = net.createServer();
        probe.once('error', reject);
        probe.listen(0, '127.0.0.1', () => {
            const port = probe.address().port;
            probe.close(() => resolve(port));
        });
    });
}
function request(port, route, headers = {}, method = 'GET', body = '') {
    return new Promise((resolve, reject) => {
        const req = http.request({ host: '127.0.0.1', port, path: route, method, headers }, (res) => {
            const chunks = [];
            res.on('data', (chunk) => chunks.push(chunk));
            res.on('end', () => resolve({
                status: res.statusCode,
                headers: res.headers,
                body: Buffer.concat(chunks).toString('utf8'),
            }));
        });
        req.on('error', reject);
        req.end(body);
    });
}
async function until(test, label) {
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await test()) return;
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    throw new Error('Timed out: ' + label);
}
async function run() {
    const fixture = spawnSync('php', [path.join(packageRoot, 'tools/rendered/workflow-live-fixture.php'), project], {
        cwd: packageRoot, encoding: 'utf8', timeout: 15000,
    });
    assert.equal(fixture.status, 0, fixture.stderr);
    const port = await freePort();
    server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', path.join(packageRoot, 'public'),
        path.join(packageRoot, 'public/index.php')], {
        cwd: packageRoot, stdio: ['ignore', 'ignore', 'pipe'],
        env: { ...process.env, AGENT_UI_INTERNAL_PROXY_SECRET: secret, AGENT_UI_PROJECT_ROOT: project,
            AGENT_UI_COMPOSER_AUTOLOAD: path.join(packageRoot, 'vendor/autoload.php') },
    });
    let errors = '';
    server.stderr.on('data', (chunk) => { errors += chunk.toString(); });
    await until(() => {
        if (server.exitCode !== null) throw new Error('PHP backend failed to start: ' + errors);
        return errors.includes('Development Server (http://');
    }, 'real isolated backend');

    const taskPath = '/task/UI-36/progress';
    const forbidden = await request(port, taskPath);
    assert.equal(forbidden.status, 403, 'direct unauthenticated task access must be blocked');
    assert.equal(forbidden.headers['set-cookie'], undefined, 'the guard must run before session_start');
    assert.ok(!forbidden.body.includes('Contract approval'), 'task data must not leak');
    assert.equal((await request(port, taskPath, { 'X-Agent-Ui-Internal-Secret': 'wrong' })).status, 403);
    assert.equal((await request(port, '/task/UI-36/progress.json')).status, 403, 'JSON is also private');
    assert.equal((await request(port, '/task/UI-36/approve',
        { 'Content-Type': 'application/x-www-form-urlencoded' }, 'POST',
        'actor=attacker&revision=1')).status, 403, 'owner decision routes are private');
    assert.equal((await request(port, '/', { 'X-Agent-Ui-Internal-Secret': secret.slice(0, -1) })).status, 403);

    const headers = { 'X-Agent-Ui-Internal-Secret': secret };
    const permitted = await request(port, taskPath, headers);
    assert.equal(permitted.status, 200, 'authenticated Node-to-PHP traffic must still render tasks');
    assert.ok(permitted.body.includes('Workflow progress'));
    assert.ok(permitted.body.includes('Approve revision 1'));
    assert.ok(!permitted.body.includes(secret), 'secret must not appear in HTML');
    assert.equal(permitted.headers['x-agent-ui-internal-secret'], undefined, 'do not reflect the private header');
    const projection = await request(port, '/task/UI-36/progress.json', headers);
    assert.equal(projection.status, 200);
    assert.equal(JSON.parse(projection.body).task_id, 'UI-36');
    assert.ok(!projection.body.includes(secret), 'secret must not appear in JSON');

    console.log('Private PHP backend: direct task/JSON/approval access blocked; gateway credential accepted.');
}

run().catch((error) => { console.error(error); process.exitCode = 1; }).finally(async () => {
    if (server && server.exitCode === null && server.signalCode === null) {
        const closed = new Promise((resolve) => server.once('exit', resolve));
        server.kill('SIGTERM');
        await Promise.race([closed, new Promise((resolve) => setTimeout(resolve, 3000))]);
        if (server.exitCode === null && server.signalCode === null) server.kill('SIGKILL');
    }
    fs.rmSync(temp, { recursive: true, force: true });
});
