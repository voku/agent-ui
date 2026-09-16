<?php
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, progress: WorkflowProgressSnapshot, human_decisions: WorkflowHumanDecisionProjection, contract: ?TaskContract, csrf_token: string} $model */
$card = $model['card'];
$taskContext = $model['task_context'];
$progress = $model['progress'];
$decisions = $model['human_decisions'];
$contract = $model['contract'];
$csrf = $model['csrf_token'];
$title = $card->id . ' · Workflow progress · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a><span>/</span>Workflow progress</p>
<?php $taskNavCurrent = '/progress'; require __DIR__ . '/../layout/task-context.php'; ?>

<div class="page-head">
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

<?php if ($decisions->allows(WorkflowHumanDecisionProjection::APPROVE_CONTRACT) && $contract !== null): ?>
    <p class="eyebrow">Human decision required</p>
    <section class="panel panel--attention">
        <div class="action__head">
            <div>
                <span class="pill pill--attention">Contract approval</span>
                <strong style="margin-left:8px">Approve revision <?= (int) $contract->revision ?></strong>
            </div>
            <span class="small faint">planned by <?= TemplateRenderer::escape($contract->plannedBy) ?></span>
        </div>

        <p style="margin:14px 0 8px"><strong>Goal:</strong> <?= TemplateRenderer::escape($contract->goal) ?></p>
        <div class="grid" style="font-size:12px;margin:8px 0">
            <div>
                <span class="faint">Scope (<?= count($contract->scope) ?>):</span>
                <div class="stack" style="margin-top:2px">
                    <?php foreach ($contract->scope as $path): ?><code><?= TemplateRenderer::escape($path) ?></code><?php endforeach; ?>
                </div>
            </div>
            <div>
                <span class="faint">Validation (<?= count($contract->validation) ?>):</span>
                <div class="stack" style="margin-top:2px">
                    <?php foreach ($contract->validation as $validation): ?><code><?= TemplateRenderer::escape($validation) ?></code><?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($contract->nonGoals !== []): ?>
            <p class="small"><span class="faint">Non-goals:</span> <?= TemplateRenderer::escape(implode(', ', $contract->nonGoals)) ?></p>
        <?php endif; ?>
        <?php if ($contract->acceptanceCriteria !== []): ?>
            <div style="margin-top:8px">
                <span class="small faint">Acceptance criteria:</span>
                <ul class="small" style="margin:4px 0 0;padding-left:18px">
                    <?php foreach ($contract->acceptanceCriteria as $criterion): ?>
                        <li><?= TemplateRenderer::escape($criterion) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/approve" style="margin-top:16px">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <p class="note" style="margin:0 0 10px">This records human authority over this exact Contract revision. It does not approve implementation, validation, review, or Learning.</p>
            <div class="form__row">
                <label class="field"><span>Approver</span><input required maxlength="200" name="actor" autocomplete="name" placeholder="who is approving"></label>
                <button class="btn btn--primary" type="submit">Approve Contract</button>
                <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/contract">Revise before approving</a>
            </div>
        </form>
    </section>
<?php elseif ($decisions->actions !== []): ?>
    <p class="eyebrow">Human decision available</p>
    <section class="panel panel--attention">
        <p style="margin:0">agent-loop projects a recordable human action for this task.</p>
        <p class="note">Only Contract approval is rendered on this workflow surface in this slice. Other typed decisions remain available on the task detail view rather than being inferred here.</p>
        <p style="margin:12px 0 0"><a class="btn btn--primary" href="/task/<?= TemplateRenderer::escape($card->id) ?>">Open task decision controls →</a></p>
    </section>
<?php endif; ?>

<p class="eyebrow">Task workflow</p>
<?php if ($progress->steps === []): ?>
    <section class="panel">
        <p class="muted">No governed workflow timeline applies to this task.</p>
    </section>
<?php else: ?>
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
    <section class="panel workflow-graph-container" data-workflow-graph style="margin-bottom: 20px; padding: 16px;">
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

    <p class="eyebrow" style="margin-top: 24px;">Workflow steps detail</p>
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
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
