<?php
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, human_decisions: WorkflowHumanDecisionProjection, contract: ?TaskContract, csrf_token: string} $model */
$card = $model['card'];
$decisions = $model['human_decisions'];
$contract = $model['contract'];
$csrf = $model['csrf_token'];
?>
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
            <input type="hidden" name="revision" value="<?= (int) $contract->revision ?>">
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
