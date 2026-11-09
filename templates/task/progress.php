<?php
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressSnapshot;
use voku\AgentUi\Runtime\LocalTerminal;
use voku\AgentUi\View\ClientScript;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, progress: WorkflowProgressSnapshot, decision_html: string, brief_html: string} $model */
$card = $model['card'];
$taskContext = $model['task_context'];
$progress = $model['progress'];
$decisionHtml = $model['decision_html'];
$briefHtml = $model['brief_html'];
$terminalTask = getenv('AGENT_UI_TERMINAL_TASK');
$terminalOrigin = LocalTerminal::origin();
$showTerminal = is_string($terminalTask) && $terminalTask === $card->id && $terminalOrigin !== null;
$title = $card->id . ' · Workflow progress · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a><span>/</span>Workflow progress</p>
<?php $taskNavCurrent = '/progress'; require __DIR__ . '/../layout/task-context.php'; ?>

<div<?= $showTerminal ? ' class="workflow-cockpit"' : '' ?>>
<div class="workflow-cockpit__progress">
<div class="page-head" data-workflow-live data-task-id="<?= TemplateRenderer::escape($card->id) ?>" data-progress-url="/task/<?= rawurlencode($card->id) ?>/progress.json">
    <h1>Workflow progress</h1>
    <p class="lede">Where this task is in the Loop-owned governed workflow. The order and status come from agent-loop; this page does not reconstruct lifecycle policy.</p>
    <p class="small note" data-workflow-live-status aria-live="polite" hidden></p>
    <button type="button" class="btn btn--small" data-workflow-live-retry hidden>Retry updates</button>
</div>

<p class="eyebrow">Current workflow state</p>
<section class="panel action">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($progress->state)) ?>" data-progress-state><?= TemplateRenderer::escape(Presentation::label($progress->state)) ?></span>
        <span class="small faint">task <?= TemplateRenderer::escape($progress->taskId) ?></span>
    </div>
    <p class="note">The canonical next action at the top of this page comes from the same agent-loop
        policy evaluation as the workflow progress below, so it is shown there once rather than twice here.</p>
</section>

<div id="workflow-overview" data-workflow-brief data-brief-digest="<?= hash('sha256', $briefHtml) ?>" aria-live="polite"><?= $briefHtml ?></div>

<div id="workflow-approval" data-workflow-decision data-decision-digest="<?= hash('sha256', $decisionHtml) ?>" aria-live="polite"><?= $decisionHtml ?></div>

<p class="eyebrow">Task workflow</p>
<section class="panel" data-progress-empty<?= $progress->steps !== [] ? ' hidden' : '' ?>>
    <p class="muted">No governed workflow timeline applies to this task.</p>
</section>
    <?php
    $graphElements = [];
    foreach ($progress->steps as $index => $step) {
        $graphElements[] = [
            'group' => 'nodes',
            'data' => [
                'id' => 'step_' . $index,
                'index' => $index + 1,
                'step_id' => $step->id,
                'label' => ($index + 1) . '. ' . $step->label,
                'status' => $step->status,
                'owner' => $step->owner,
                'reason' => $step->reason ?? '',
            ],
            'position' => [
                'x' => 100.0 + ($index * 190.0),
                'y' => 110.0,
            ],
        ];

        if ($index > 0) {
            $prevStep = $progress->steps[$index - 1];
            $graphElements[] = [
                'group' => 'edges',
                'data' => [
                    'id' => 'edge_' . ($index - 1) . '_' . $index,
                    'source' => 'step_' . ($index - 1),
                    'target' => 'step_' . $index,
                    'status' => $prevStep->status === 'done' ? 'done' : 'pending',
                ],
            ];
        }
    }
    ?>
    <section id="workflow-graph" class="panel workflow-graph-container" data-workflow-graph<?= $progress->steps === [] ? ' hidden' : '' ?> style="margin-bottom: 20px; padding: 16px;">
        <div class="action__head" style="margin-bottom: 12px;">
            <div>
                <strong style="font-size: 14px;">Workflow Graph</strong>
                <span class="small faint" style="margin-left:8px">Visual pipeline &amp; active stage · drag to arrange, scroll to zoom</span>
            </div>
            <div class="workflow-graph__tools" data-workflow-tools hidden style="display:flex;gap:6px;align-items:center">
                <button type="button" class="btn btn--small" data-wf-zoom="in" title="Zoom in">+</button>
                <button type="button" class="btn btn--small" data-wf-zoom="out" title="Zoom out">−</button>
                <button type="button" class="btn btn--small" data-wf-zoom="fit" title="Fit to viewport">Fit</button>
                <button type="button" class="btn btn--small" data-wf-reset title="Reset positions">Reset</button>
            </div>
        </div>
        <div class="workflow-graph__canvas" data-workflow-canvas
             data-workflow-elements="<?= TemplateRenderer::escape(json_encode($graphElements, JSON_THROW_ON_ERROR)) ?>"
             style="width: 100%; height: 230px; background: var(--surface-alt); border: 1px solid var(--rule); border-radius: var(--radius-sm); position: relative; overflow: hidden;">
        </div>
        <div class="workflow-graph__inspector" data-workflow-inspector hidden style="margin-top: 12px; padding: 10px 14px; background: var(--surface); border: 1px solid var(--rule); border-radius: var(--radius-sm);">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                <div style="display:flex;align-items:center;gap:8px">
                    <span class="mono small faint" data-wf-inspector-index></span>
                    <strong data-wf-inspector-label></strong>
                    <span class="pill" data-wf-inspector-pill></span>
                </div>
                <span class="small faint" data-wf-inspector-owner></span>
            </div>
            <p class="note" data-wf-inspector-reason style="margin:8px 0 0" hidden></p>
        </div>
    </section>

    <div id="workflow-steps" data-progress-details<?= $progress->steps === [] ? ' hidden' : '' ?>>
    <p class="eyebrow" style="margin-top: 24px;">Workflow steps detail</p>
    <ol class="stack" data-progress-list style="list-style:none;padding:0;margin:0">
        <?php foreach ($progress->steps as $index => $step): ?>
            <?php
            $tone = match ($step->status) {
                'done' => 'ok',
                'current' => 'attention',
                'blocked' => 'blocked',
                default => 'neutral',
            };
            ?>
            <li id="step-panel-<?= (int) $index ?>" class="panel<?= $step->status === 'blocked' ? ' panel--danger' : ($step->status === 'current' ? ' panel--attention' : '') ?>" style="margin:0">
                <div class="action__head">
                    <div style="display:flex;gap:10px;align-items:center;min-width:0">
                        <span class="mono small faint"><?= (int) $index + 1 ?></span>
                        <strong><?= TemplateRenderer::escape($step->label) ?></strong>
                        <span class="pill pill--<?= TemplateRenderer::escape($tone) ?>"><?= TemplateRenderer::escape(Presentation::label($step->status)) ?></span>
                    </div>
                    <span class="small faint">owner: <?= TemplateRenderer::escape($step->owner) ?></span>
                </div>
                <?php if ($step->reason !== null): ?>
                    <p class="note" style="margin-top:8px"><?= TemplateRenderer::escape($step->reason) ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <p class="note">Done, current, pending, blocked and not-applicable remain distinct owner-projected states. agent-ui does not calculate a percentage complete.</p>
    </div>
</div>
<?php if ($showTerminal): ?>
<aside id="workflow-terminal" class="workflow-cockpit__terminal panel" aria-label="Local terminal session" data-terminal-panel>
    <div class="action__head">
        <strong>Local terminal session</strong>
        <div>
            <button type="button" class="btn btn--small" data-terminal-toggle hidden>Disconnect</button>
            <button type="button" class="btn btn--small" data-terminal-stop hidden>Stop session</button>
        </div>
    </div>
    <p class="note">Use your preferred CLI in the task's project directory. Run <code>vendor/bin/agent-loop enter <?= TemplateRenderer::escape($card->id) ?> --format=json</code>, follow the owner-provided next action, and use <code>finish</code> to reconcile evidence. The workflow panel refreshes independently; terminal output is not authority.</p>
    <p class="small"><strong>Task:</strong> <code><?= TemplateRenderer::escape($card->id) ?></code> · <strong>Project:</strong> <code>AGENT_UI_PROJECT_ROOT</code> · <strong>Context:</strong> <code>AGENT_LOOP_TASK_ID</code></p>
    <p class="small" data-terminal-state aria-live="polite">Live session status requires JavaScript.</p>
    <div data-terminal-start hidden>
        <label for="terminal-host">Start terminal</label>
        <select id="terminal-host" data-terminal-host>
            <option value="shell">Interactive shell (any CLI)</option>
            <option value="codex">Codex shortcut</option>
            <option value="claude">Claude Code shortcut</option>
        </select>
        <button type="button" class="btn btn--primary btn--small" data-terminal-start-button>Start session</button>
    </div>
    <section class="codex-app-server panel" data-codex-app-server aria-label="Codex App Server session">
        <div class="action__head">
            <strong>Codex App Server <span class="pill pill--neutral">observation</span></strong>
            <button type="button" class="btn btn--small" data-codex-app-server-stop hidden>Stop Codex session</button>
        </div>
        <p class="note">This is a separate browser-controlled Codex session using structured App Server events. It does not change agent-loop workflow state or answer requests automatically.</p>
        <p class="small" data-codex-app-server-state aria-live="polite">Checking Codex App Server…</p>
        <p class="small muted" data-codex-app-server-message></p>
        <p class="small" data-codex-app-server-feedback aria-live="polite"></p>
        <div class="btn-row">
            <button type="button" class="btn btn--primary btn--small" data-codex-app-server-start>Start Codex App Server</button>
        </div>
        <form class="codex-app-server__prompt" data-codex-app-server-form hidden>
            <label for="codex-app-server-prompt">Send a prompt to this Codex session</label>
            <textarea id="codex-app-server-prompt" data-codex-app-server-prompt rows="3" maxlength="12288" required></textarea>
            <button type="submit" class="btn btn--primary btn--small" data-codex-app-server-send>Send prompt</button>
            <p class="note">Prompts are sent directly to Codex and are not copied into this activity feed.</p>
        </form>
        <div class="codex-app-server__requests" data-codex-app-server-requests hidden>
            <h3>Requests waiting for a human</h3>
            <div data-codex-app-server-request-list></div>
        </div>
        <div class="codex-app-server__activity">
            <h3>Recent structured activity</h3>
            <ol data-codex-app-server-activity aria-live="off"><li class="muted">No Codex activity yet.</li></ol>
        </div>
    </section>
    <div class="panel" data-terminal-attachment hidden>
        <label for="terminal-image-file">Attach screenshot or image (PNG, JPEG, WebP, GIF; max 8 MiB)</label>
        <input type="file" id="terminal-image-file" data-terminal-image-file accept="image/png,image/jpeg,image/webp,image/gif">
        <button type="button" class="btn btn--small" data-terminal-image-clipboard>Paste image from clipboard</button>
        <p class="note">The image is stored privately for this task. Its path is pasted into the terminal without pressing Enter. Drop an image here or choose a file if clipboard access is unavailable.</p>
    </div>
    <iframe title="Local interactive terminal" sandbox="allow-scripts allow-same-origin" src="<?= TemplateRenderer::escape($terminalOrigin . '/_terminal/') ?>" data-terminal-frame data-terminal-url="<?= TemplateRenderer::escape($terminalOrigin . '/_terminal/') ?>"></iframe>
    <p class="note" data-terminal-message>Connection status appears in the terminal. Disconnecting leaves the session running.</p>
</aside>
<?php endif; ?>
</div>

<?php if (ClientScript::graphLibrary() !== ''): ?><script><?= ClientScript::graphLibrary() ?></script><?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
