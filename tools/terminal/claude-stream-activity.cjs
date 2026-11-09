'use strict';

// Claude's documented stream-json CLI surface. Read-only activity projection;
// permission prompts are deliberately denied, never inferred from tool output.
const { spawn } = require('node:child_process');
const { StringDecoder } = require('node:string_decoder');

const eventLimit = 40;
const lineLimit = 256 * 1024;
const inputLimit = 12 * 1024;

function bounded(value, limit = 100) {
    if (typeof value !== 'string') return '';
    const clean = value.replace(/[\u0000-\u001f\u007f]/g, ' ').trim();
    return clean.length > limit ? clean.slice(0, limit - 1) + '…' : clean;
}

class ClaudeStreamActivity {
    constructor(executable, root, taskId) {
        this.executable = executable;
        this.root = root;
        this.taskId = taskId;
        this.process = null;
        this.state = 'stopped';
        this.events = [];
        this.eventId = 1;
        this.updated = null;
        this.decoder = new StringDecoder('utf8');
        this.buffer = '';
        this.expectedStop = false;
    }

    record(kind, label) {
        const event = { id: this.eventId++, at: new Date().toISOString(), kind: bounded(kind, 32), label: bounded(label, 160) };
        this.events.push(event);
        if (this.events.length > eventLimit) this.events.shift();
        this.updated = event.at;
    }

    snapshot(available) {
        return {
            provider: 'claude',
            available,
            running: Boolean(this.process && this.process.exitCode === null && this.process.signalCode === null),
            state: available ? this.state : 'unavailable',
            message: this.state === 'idle' ? 'Claude is ready for a prompt. Activity is observational.'
                : this.state === 'running' ? 'Claude is working. Activity is observational.'
                    : this.state === 'error' ? 'Claude structured session failed. Stop and restart it.'
                        : this.state === 'stopped' ? 'No Claude structured session is running.'
                            : 'Claude structured session is starting or stopping.',
            last_updated: this.updated,
            activity: this.events.slice(),
            pending_requests: [],
            pending_overflow: false,
            supports_decisions: false,
            decision_message: 'Claude tool approvals require a supported Agent SDK permission callback. They are not handled here; unanswered permission prompts are denied.',
        };
    }

    start() {
        if (this.process && this.process.exitCode === null && this.process.signalCode === null) {
            throw new Error('Claude structured session is already running.');
        }
        this.decoder = new StringDecoder('utf8');
        this.buffer = '';
        this.expectedStop = false;
        this.state = 'starting';
        // --permission-prompts none is a documented fail-closed noninteractive
        // mode. No --dangerously-skip-permissions or undocumented stdio hooks.
        const args = ['-p', '--input-format', 'stream-json', '--output-format', 'stream-json',
            '--verbose', '--permission-mode', 'default', '--permission-prompts', 'none'];
        const child = spawn(this.executable, args, {
            cwd: this.root,
            env: { ...process.env, AGENT_LOOP_TASK_ID: this.taskId, AGENT_UI_PROJECT_ROOT: this.root },
            stdio: ['pipe', 'pipe', 'pipe'],
        });
        this.process = child;
        this.record('session', 'Starting Claude structured session.');
        child.stdout.on('data', (data) => this.receive(data, child));
        // stderr may contain credentials or provider diagnostics. Never
        // forward it to the browser, logs, or structured activity projection.
        child.stderr.on('data', () => {});
        child.stdin.on('error', () => this.fail(child));
        child.on('error', () => this.fail(child));
        child.on('exit', () => {
            if (this.process !== child) return;
            this.process = null;
            if (this.expectedStop) {
                this.state = 'stopped';
                this.record('session', 'Claude structured session stopped.');
            } else {
                this.state = 'error';
                this.record('error', 'Claude structured session exited unexpectedly.');
            }
        });
        // This means the transport is ready to accept stdin, not that an
        // authenticated model completed a request. Only a real result proves it.
        this.state = 'idle';
        return this.snapshot(true);
    }

    sendPrompt(prompt) {
        if (this.state !== 'idle' || !this.process || this.process.stdin.destroyed ||
            typeof prompt !== 'string' || !prompt.trim() || Buffer.byteLength(prompt, 'utf8') > inputLimit) {
            throw new Error('Claude cannot accept this prompt.');
        }
        this.state = 'running';
        this.record('turn', 'A prompt was sent to Claude.');
        const payload = { type: 'user', message: { role: 'user', content: [{ type: 'text', text: prompt.trim() }] } };
        if (!this.process.stdin.write(JSON.stringify(payload) + '\n')) {
            // A full stream buffer is not an authorization failure: write()
            // returns false for backpressure, not necessarily an unsent turn.
        }
        return this.snapshot(true);
    }

    receive(chunk, source) {
        if (source !== this.process) return;
        this.buffer += this.decoder.write(chunk);
        if (this.buffer.length > lineLimit && !this.buffer.includes('\n')) return this.fail(source);
        let index;
        while ((index = this.buffer.indexOf('\n')) !== -1) {
            const line = this.buffer.slice(0, index).replace(/\r$/, '');
            this.buffer = this.buffer.slice(index + 1);
            if (line.length > lineLimit) return this.fail(source);
            if (!line) continue;
            let event;
            try { event = JSON.parse(line); } catch (_) { return this.fail(source); }
            if (!event || typeof event !== 'object' || Array.isArray(event)) continue;
            this.onEvent(event);
        }
    }

    onEvent(event) {
        if (event.type === 'system' && event.subtype === 'init') {
            this.record('session', 'Claude initialized its structured session.');
        } else if (event.type === 'assistant' && event.message && Array.isArray(event.message.content)) {
            for (const block of event.message.content) {
                if (!block || block.type !== 'tool_use') continue;
                // Deliberately never surface raw prompt, tool input or model
                // output. A provider-reported tool name is observational only.
                this.record('tool', 'Claude requested tool ' + bounded(block.name, 64) + '.');
            }
        } else if (event.type === 'user' && event.message && Array.isArray(event.message.content)) {
            if (event.message.content.some((block) => block && block.type === 'tool_result')) {
                this.record('tool', 'Claude received a tool result.');
            }
        } else if (event.type === 'result') {
            this.state = event.is_error === true ? 'error' : 'idle';
            this.record('turn', event.is_error === true ? 'Claude turn failed.' : 'Claude completed a turn.');
        } else if (event.type === 'system' && event.subtype === 'permission_denied') {
            this.record('permission', 'Claude denied an unanswered permission request.');
        }
    }

    fail(child) {
        if (this.process !== child) return;
        this.state = 'error';
        this.record('error', 'Claude sent an invalid or disconnected structured stream.');
        if (child.exitCode === null && child.signalCode === null) child.kill('SIGTERM');
    }

    async stop() {
        const child = this.process;
        if (!child) { this.state = 'stopped'; return this.snapshot(true); }
        this.expectedStop = true;
        this.state = 'stopping';
        if (child.exitCode === null && child.signalCode === null) {
            const stopped = new Promise((resolve) => {
                const timeout = setTimeout(resolve, 1500);
                child.once('exit', () => { clearTimeout(timeout); resolve(); });
            });
            child.kill('SIGTERM');
            await stopped;
            if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL');
        }
        if (this.process === child) {
            this.process = null;
            this.state = 'stopped';
            this.record('session', 'Claude structured session stopped.');
        }
        return this.snapshot(true);
    }
}

module.exports = { ClaudeStreamActivity };
