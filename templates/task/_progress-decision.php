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

        <div class="workflow-brief__next" style="margin-top:12px">
            <strong>What this decision means</strong>
            <p class="small" style="margin:5px 0 0">Approving gives the coding agent authority to work only within this exact Contract revision and its listed scope. Validation, evidence, review and closeout remain separate gates. Nothing here claims that the work or tests are done.</p>
            <p class="small" style="margin:5px 0 0">To change the goal, allowed paths or acceptance criteria, revise the Contract instead of approving it. The agent must then follow the next owner-projected action.</p>
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

        <form class="form" method="post" data-workflow-approval-form data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/approve" style="margin-top:16px">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <input type="hidden" name="revision" value="<?= (int) $contract->revision ?>">
            <p class="note" style="margin:0 0 10px">This records human authority over this exact Contract revision. It does not approve implementation, validation, review, or Learning.</p>
            <div class="form__row">
                <label class="field"><span>Approver</span><input required maxlength="200" name="actor" autocomplete="name" placeholder="who is approving"></label>
                <button class="btn btn--primary" type="submit">Approve Contract</button>
                <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/contract">Revise before approving</a>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
    </section>
<?php elseif ($decisions->allows(WorkflowHumanDecisionProjection::ACKNOWLEDGE_REVIEW) && $decisions->reviewReportSha256 !== null): ?>
    <p class="eyebrow">Human review acknowledgement</p>
    <section class="panel panel--attention">
        <div class="action__head">
            <strong>Review the exact report before acknowledging</strong>
            <span class="pill pill--attention">Owner decision available</span>
        </div>
        <p class="note">This decision is bound to the current report's SHA-256. Open the full review to inspect its findings; acknowledging does not declare the implementation correct or waive other workflow gates.</p>
        <p class="mono small" style="overflow-wrap:anywhere"><strong>Report SHA-256:</strong> <?= TemplateRenderer::escape($decisions->reviewReportSha256) ?></p>
        <form class="form" method="post" data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/review-ack">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <input type="hidden" name="report_sha256" value="<?= TemplateRenderer::escape($decisions->reviewReportSha256) ?>">
            <div class="form__row">
                <label class="field"><span>Reviewed by</span><input required maxlength="200" name="actor" autocomplete="name"></label>
                <button class="btn btn--primary" type="submit">Acknowledge exact report</button>
                <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/work">Inspect work &amp; review</a>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
    </section>
<?php elseif ($decisions->allows(WorkflowHumanDecisionProjection::RECORD_LEARNING)): ?>
    <p class="eyebrow">Learning disposition</p>
    <section class="panel panel--attention">
        <strong>Record the outcome of this run</strong>
        <p class="note">Choose from the currently supported owner actions. No durable learning means there was no reusable finding; a follow-up requires a concrete reference. This is not a substitute for validation or review.</p>
        <form class="form" method="post" data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/learning">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <input type="hidden" name="decision" value="no_durable_learning">
            <h3>No durable learning</h3>
            <div class="form__row">
                <label class="field"><span>Decided by</span><input required maxlength="200" name="actor" autocomplete="name"></label>
                <label class="field field--wide"><span>Reason</span><textarea required maxlength="2000" name="reason"></textarea></label>
                <button class="btn" type="submit">Record no durable learning</button>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
        <form class="form" method="post" data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/learning" style="margin-top:18px">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <input type="hidden" name="decision" value="follow_up_required">
            <h3>Follow-up required</h3>
            <div class="form__row">
                <label class="field"><span>Decided by</span><input required maxlength="200" name="actor" autocomplete="name"></label>
                <label class="field"><span>Follow-up reference</span><input required maxlength="500" name="follow_up_ref"></label>
                <label class="field field--wide"><span>Reason</span><textarea required maxlength="2000" name="reason"></textarea></label>
                <button class="btn" type="submit">Record follow-up</button>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
        <p class="small note">Validated Finding content still belongs to the Learning owner. Use the full task decision page for any other projected action.</p>
    </section>
<?php elseif ($decisions->actions !== []): ?>
    <p class="eyebrow">Human decision available</p>
    <section class="panel panel--attention">
        <p class="note">The owner projects another recordable decision. Its details are available on the full task page; this cockpit will not invent a generic approval.</p>
        <a class="btn btn--primary" href="/task/<?= TemplateRenderer::escape($card->id) ?>#decision">Open exact owner decision</a>
    </section>
<?php endif; ?>
