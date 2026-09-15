<?php
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, progress: WorkflowProgressSnapshot} $model */
$card = $model['card'];
$progress = $model['progress'];
$title = $card->id . ' · Workflow progress · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a><span>/</span>Workflow progress</p>

<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($card->id) ?></span>
    <h1>Workflow progress</h1>
    <p class="lede">Where this task is in the Loop-owned governed workflow. The order and status come from agent-loop; this page does not reconstruct lifecycle policy.</p>
</div>

<p class="eyebrow">Current workflow state</p>
<section class="panel action">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($progress->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($progress->state)) ?></span>
        <span class="small faint">task <?= TemplateRenderer::escape($progress->taskId) ?></span>
    </div>
    <h2 style="margin-top:16px">Canonical next action</h2>
    <p class="action__hint"><?= TemplateRenderer::escape(Presentation::nextActionKindHint($progress->nextActionKind)) ?></p>
    <div class="codeblock"><pre><?= TemplateRenderer::escape($progress->nextAction) ?></pre></div>
    <p class="note">The action is carried from the same agent-loop policy evaluation as the workflow progress below.</p>
</section>

<p class="eyebrow">Task workflow</p>
<?php if ($progress->steps === []): ?>
    <section class="panel">
        <p class="muted">No governed workflow timeline applies to this task.</p>
    </section>
<?php else: ?>
    <ol class="stack" style="list-style:none;padding:0;margin:0">
        <?php foreach ($progress->steps as $index => $step): ?>
            <?php
            $tone = match ($step->status) {
                'done' => 'ok',
                'current' => 'attention',
                'blocked' => 'blocked',
                default => 'neutral',
            };
            ?>
            <li class="panel<?= $step->status === 'blocked' ? ' panel--danger' : ($step->status === 'current' ? ' panel--attention' : '') ?>" style="margin:0">
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
<?php endif; ?>

<?php $taskNavId = $card->id; $taskNavCurrent = '/progress'; require __DIR__ . '/../layout/task-nav.php'; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
