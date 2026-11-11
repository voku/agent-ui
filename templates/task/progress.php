<?php
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressSnapshot;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerSnapshot;
use voku\AgentUi\Runtime\LocalTerminal;
use voku\AgentUi\View\ClientScript;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, progress: WorkflowProgressSnapshot, decision_html: string, brief_html: string, runner?: ?RunnerSnapshot, csrf_token?: string} $model */
$card = $model['card'];
$taskContext = $model['task_context'];
$progress = $model['progress'];
$decisionHtml = $model['decision_html'];
$briefHtml = $model['brief_html'];
$runner = $model['runner'] ?? null;
$csrf = $model['csrf_token'] ?? '';
$terminalTask = getenv('AGENT_UI_TERMINAL_TASK');
$terminalOrigin = LocalTerminal::origin();
$showTerminal = is_string($terminalTask) && $terminalTask === $card->id && $terminalOrigin !== null;
$title = $card->id . ' · Workspace · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a><span>/</span>Workspace</p>
<?php $taskNavCurrent = '/progress'; require __DIR__ . '/../layout/task-context.php'; ?>

<?php $humanWaiting = ($model['human_waiting'] ?? false) === true; ?>
<div class="workspace<?= $showTerminal ? ' workspace--terminal' : '' ?>" data-workspace data-workspace-task="<?= TemplateRenderer::escape($card->id) ?>">
<?php if ($showTerminal): ?>
<div class="workspace__toolbar" data-workspace-toolbar hidden role="toolbar" aria-label="Workspace layout">
    <span class="small faint">Layout</span>
    <div class="segmented" role="group" aria-label="Arrange panels">
        <button type="button" class="btn btn--small" data-workspace-layout="split" aria-pressed="true" title="Workflow and terminal side by side">Side by side</button>
        <button type="button" class="btn btn--small" data-workspace-layout="stack" aria-pressed="false" title="Terminal below the workflow, full width">Stacked</button>
        <button type="button" class="btn btn--small" data-workspace-layout="terminal" aria-pressed="false" title="Only the terminal">Terminal only</button>
        <button type="button" class="btn btn--small" data-workspace-layout="work" aria-pressed="false" title="Only the workflow panels">Workflow only</button>
    </div>
    <span class="small faint">Terminal height</span>
    <div class="segmented" role="group" aria-label="Terminal height">
        <button type="button" class="btn btn--small" data-workspace-height="-1" title="Shorter terminal" aria-label="Shorter terminal">−</button>
        <output class="small mono" data-workspace-height-value aria-live="polite"></output>
        <button type="button" class="btn btn--small" data-workspace-height="1" title="Taller terminal" aria-label="Taller terminal">+</button>
        <button type="button" class="btn btn--small" data-workspace-height="fit" title="Fill the visible window">Fit window</button>
    </div>
    <button type="button" class="btn btn--small" data-workspace-fullscreen>Fullscreen terminal</button>
    <button type="button" class="btn btn--small btn--ghost" data-workspace-reset>Reset layout</button>
</div>
<?php endif; ?>
<div class="workspace__work workflow-cockpit__progress" data-workspace-work>
<nav class="workspace-tabs" aria-label="Workspace panels" data-workspace-tabs>
    <a class="workspace-tabs__tab" href="#ws-overview" data-workspace-tab="overview">Overview<?php if ($humanWaiting): ?> <span class="pill pill--attention workspace-tabs__badge" data-workspace-decision-badge>decision</span><?php endif; ?></a>
    <a class="workspace-tabs__tab" href="#ws-steps" data-workspace-tab="steps">Workflow steps <span class="small faint" data-workspace-step-count><?= count($progress->steps) ?></span></a>
    <a class="workspace-tabs__tab" href="#ws-prompts" data-workspace-tab="prompts">Prompts</a>
</nav>
<section class="workspace__panel" id="ws-overview" data-workspace-panel="overview" aria-label="Overview">
<div class="workspace__head" data-workflow-live data-task-id="<?= TemplateRenderer::escape($card->id) ?>" data-progress-url="/task/<?= rawurlencode($card->id) ?>/progress.json">
    <h1>Workspace</h1>
    <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($progress->state)) ?>" data-progress-state><?= TemplateRenderer::escape(Presentation::label($progress->state)) ?></span>
    <p class="lede">Owner state from agent-loop beside your CLI. This page renders owner projections; it does not decide what happens next.</p>
    <p class="small note" data-workflow-live-status aria-live="polite" hidden></p>
    <button type="button" class="btn btn--small" data-workflow-live-retry hidden>Retry updates</button>
</div>

<div id="workflow-overview" data-workflow-brief data-brief-digest="<?= hash('sha256', $briefHtml) ?>" aria-live="polite"><?= $briefHtml ?></div>

<div id="workflow-approval" data-workflow-decision data-decision-digest="<?= hash('sha256', $decisionHtml) ?>" aria-live="polite"><?= $decisionHtml ?></div>

<?php if ($runner !== null && $runner->installed): ?>
    <h2 class="eyebrow">Execution continuation</h2>
    <section class="panel panel--accent" aria-label="Execution continuation">
        <div class="action__head">
            <div>
                <strong>Execution continuation ready</strong>
                <span class="pill pill--<?= ($runner->allowResume || $runner->allowRun) ? 'ok' : 'neutral' ?>" style="margin-left:8px"><?= $runner->allowResume ? 'resumable' : ($runner->allowRun ? 'runnable' : 'managed runner') ?></span>
            </div>
            <span class="small faint">stage <?= TemplateRenderer::escape($runner->currentStageId ?? 'execution') ?> · attempt <?= (int) ($runner->currentAttempt ?? 1) ?></span>
        </div>
        <p class="small" style="margin:10px 0 12px">Human decisions are satisfied. Continue execution directly through the managed runner or switch to guided prompt handoff.</p>
        <div class="btn-row">
            <?php if ($runner->allowResume): ?>
                <form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/runner/resume" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                    <button class="btn btn--primary" type="submit">Resume execution</button>
                </form>
            <?php elseif ($runner->allowRun): ?>
                <form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/runner/run" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                    <button class="btn btn--primary" type="submit">Run stage</button>
                </form>
            <?php else: ?>
                <button class="btn" type="button" disabled title="Runner control unavailable in current environment">Resume execution</button>
            <?php endif; ?>
            <a class="btn" href="/task/<?= rawurlencode($card->id) ?>/prompts?recipe=continue-until-done">Continue via Prompt Workbench →</a>
        </div>
    </section>
<?php endif; ?>

</section>

<section class="workspace__panel" id="ws-steps" data-workspace-panel="steps" aria-label="Workflow steps">
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
</section>

<section class="workspace__panel" id="ws-prompts" data-workspace-panel="prompts" aria-label="Prompts">
    <p class="eyebrow">Owner-provided prompts</p>
    <div data-workspace-prompts data-workspace-prompts-src="/task/<?= rawurlencode($card->id) ?>/prompts?_fragment=form">
        <section class="panel">
            <p style="margin:0">Pick an agent-recall-compiler recipe, fill only the arguments it declares, and generate a deterministic prompt for this task. Copy it into your terminal; multi-line prompts are never inserted for you.</p>
            <p class="btn-row" style="margin:12px 0 0"><a class="btn btn--primary btn--small" href="/task/<?= rawurlencode($card->id) ?>/prompts">Open the Prompt Workbench</a> <a class="btn btn--small" href="/task/<?= rawurlencode($card->id) ?>/prompts?recipe=continue-until-done">Continue until done</a></p>
        </section>
    </div>
</section>
</div>
<?php if ($showTerminal): ?>
<div class="workspace__splitter" data-workspace-splitter hidden role="separator" tabindex="0" aria-orientation="vertical" aria-label="Resize terminal width" aria-controls="workflow-terminal" aria-valuemin="20" aria-valuemax="80" aria-valuenow="50" title="Drag or use the arrow keys to resize"></div>
<aside id="workflow-terminal" class="workspace__terminal workflow-cockpit__terminal panel" aria-label="Local terminal session" data-terminal-panel>
    <div class="terminal-head">
        <strong>Terminal</strong>
        <span class="small faint" data-terminal-state aria-live="polite">Live session status requires JavaScript.</span>
        <span class="terminal-head__actions">
            <button type="button" class="btn btn--small" data-terminal-toggle hidden>Disconnect</button>
            <button type="button" class="btn btn--small" data-terminal-stop hidden>Stop session</button>
        </span>
    </div>
    <div class="terminal-start" data-terminal-start hidden>
        <label for="terminal-host">Start in this task's project</label>
        <select id="terminal-host" data-terminal-host>
            <option value="shell">Interactive shell (any CLI)</option>
            <option value="agy">Antigravity (agy) shortcut</option>
            <option value="codex">Codex shortcut</option>
            <option value="claude">Claude Code shortcut</option>
        </select>
        <button type="button" class="btn btn--primary btn--small" data-terminal-start-button>Start session</button>
    </div>
    <div class="workspace__frame" data-workspace-frame>
        <iframe title="Local interactive terminal" sandbox="allow-scripts allow-same-origin" src="<?= TemplateRenderer::escape($terminalOrigin . '/_terminal/') ?>" data-terminal-frame data-terminal-url="<?= TemplateRenderer::escape($terminalOrigin . '/_terminal/') ?>"></iframe>
    </div>
    <div class="workspace__height-handle" data-workspace-height-handle hidden role="separator" tabindex="0" aria-orientation="horizontal" aria-label="Resize terminal height" title="Drag or use the arrow keys to resize"></div>
    <p class="small note" data-terminal-message aria-live="polite">Connection status appears in the terminal. Disconnecting leaves the session running.</p>
    <div class="terminal-quick" aria-label="Quick commands">
        <span class="small faint">Quick commands</span>
        <div class="codeblock codeblock--compact"><pre id="terminal-cmd-enter">vendor/bin/agent-loop enter <?= TemplateRenderer::escape($card->id) ?> --format=json</pre><button type="button" class="copy" hidden data-copy-target="terminal-cmd-enter">Copy</button><button type="button" class="terminal-insert" hidden data-terminal-insert-target="terminal-cmd-enter" title="Paste into the local terminal without pressing Enter">Insert</button></div>
        <div class="codeblock codeblock--compact"><pre id="terminal-cmd-finish">vendor/bin/agent-loop finish <?= TemplateRenderer::escape($card->id) ?> --format=json</pre><button type="button" class="copy" hidden data-copy-target="terminal-cmd-finish">Copy</button><button type="button" class="terminal-insert" hidden data-terminal-insert-target="terminal-cmd-finish" title="Paste into the local terminal without pressing Enter">Insert</button></div>
        <p class="small note">The canonical next action above can be inserted the same way; generated prompts in the <a href="#ws-prompts" data-workspace-tab-link="prompts">Prompts</a> panel are Copy-only. Nothing is submitted for you; terminal output is never workflow authority.</p>
    </div>
    <details class="terminal-tools" data-terminal-tools>
        <summary>Session tools · images, external terminal, environment</summary>
        <div class="panel" data-terminal-attachment hidden>
            <label for="terminal-image-file">Attach screenshot or image (PNG, JPEG, WebP, GIF; max 8 MiB)</label>
            <input type="file" id="terminal-image-file" data-terminal-image-file accept="image/png,image/jpeg,image/webp,image/gif">
            <button type="button" class="btn btn--small" data-terminal-image-clipboard>Paste image from clipboard</button>
            <p class="note">The image is stored privately for this task. Its path is pasted into the terminal without pressing Enter. Drop an image here or choose a file if clipboard access is unavailable.</p>
        </div>
        <p class="small faint" data-terminal-attach-note hidden><strong>External terminal:</strong> <code id="terminal-attach-cmd" data-terminal-attach-cmd></code> <button type="button" class="copy copy--inline" hidden data-copy-target="terminal-attach-cmd">Copy</button></p>
        <p class="small"><strong>Task:</strong> <code><?= TemplateRenderer::escape($card->id) ?></code> · <strong>Project:</strong> <code>AGENT_UI_PROJECT_ROOT</code> · <strong>Context:</strong> <code>AGENT_LOOP_TASK_ID</code></p>
    </details>
</aside>
<?php endif; ?>
</div>

<?php if (ClientScript::graphLibrary() !== ''): ?><script><?= ClientScript::graphLibrary() ?></script><?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
