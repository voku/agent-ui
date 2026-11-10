'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-ui-dependencies-'));
const project = path.join(tmp, 'project');
const bin = path.join(tmp, 'bin');
fs.mkdirSync(bin);
const php = (process.env.PATH || '').split(path.delimiter)
    .map((directory) => path.join(directory || '.', 'php'))
    .find((candidate) => {
        try { fs.accessSync(candidate, fs.constants.X_OK); return fs.statSync(candidate).isFile(); }
        catch (_) { return false; }
    });
assert.ok(php, 'PHP must be available on PATH');
fs.symlinkSync(path.resolve(php), path.join(bin, 'php'));
const fixture = spawnSync('php', [path.join(root, 'tools/rendered/workflow-live-fixture.php'), project], { encoding: 'utf8' });
assert.equal(fixture.status, 0, fixture.stderr);

async function check() {
    const listener = net.createServer();
    await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
    const port = listener.address().port;
    await new Promise((resolve) => listener.close(resolve));
    const origin = `http://127.0.0.1:${port}`;
    const child = spawn(process.execPath, [path.join(__dirname, 'local-cockpit.cjs'), `--root=${project}`, '--task=UI-36', '--serve', `--port=${port}`], {
        env: { ...process.env, PATH: bin }, stdio: ['ignore', 'pipe', 'pipe'],
    });
    let output = '', errors = '';
    child.stdout.on('data', (data) => { output += data; });
    child.stderr.on('data', (data) => { errors += data; });
    try {
        const deadline = Date.now() + 15000;
        while (!output.includes('Terminal transport unavailable;') && Date.now() < deadline) {
            if (child.exitCode !== null) throw new Error(errors);
            await new Promise((resolve) => setTimeout(resolve, 50));
        }
        assert.match(output, /Missing required terminal tools: ttyd, tmux\./);
        const token = output.match(/\/_local\/access#([^\s]+)/)[1];
        const login = await fetch(origin + '/_local/login', { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json' }, body: JSON.stringify({ token }) });
        assert.equal(login.status, 204);
        const cookie = login.headers.get('set-cookie').split(';')[0];
        const response = await fetch(origin + '/_local/session', { headers: { Cookie: cookie } });
        const status = await response.json();
        assert.equal(status.terminal_available, false);
        assert.match(status.terminal_error, /Missing required terminal tools: ttyd, tmux\./);
        assert.equal((await fetch(origin + '/task/UI-36/progress', { headers: { Cookie: cookie } })).status, 200);
        console.log('Missing terminal dependencies are reported while the workflow remains available.');
    } finally {
        const exited = new Promise((resolve) => child.once('exit', resolve));
        child.kill('SIGTERM');
        await exited;
    }
    const start = spawnSync(process.execPath, [path.join(__dirname, 'local-cockpit.cjs'), `--root=${project}`, '--task=UI-36', '--start=shell', `--port=${port}`], {
        env: { ...process.env, PATH: bin }, encoding: 'utf8', timeout: 15000,
    });
    assert.notEqual(start.status, 0);
    assert.match(start.stderr, /Missing required terminal tools: ttyd, tmux\./);
}
check().finally(() => fs.rmSync(tmp, { recursive: true, force: true })).catch((error) => { console.error(error); process.exitCode = 1; });
