<?php
use voku\AgentLoop\Execution\AttentionRequest;
use voku\AgentLoop\Execution\AttentionResolution;
use voku\AgentLoop\Workflow\WorkflowAttentionProjection;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, human_decisions: WorkflowHumanDecisionProjection, contract: ?TaskContract, csrf_token: string, pending_attention?: ?AttentionRequest, attention_decision?: ?WorkflowAttentionProjection, attention_history?: list<AttentionResolution>, head_commit?: ?string} $model */
$card = $model['card'];
$decisions = $model['human_decisions'];
$contract = $model['contract'];
$csrf = $model['csrf_token'];
$pendingAttention = $model['pending_attention'] ?? null;
$attentionDecision = $model['attention_decision'] ?? null;
$attentionHistory = $model['attention_history'] ?? [];
$headCommit = $model['head_commit'] ?? null;
$reviewUrl = '/task/' . rawurlencode($card->id) . '/review.html';
?>
<?php if ($pendingAttention !== null): ?>
    <h2 class="eyebrow">Human attention required</h2>
    <section class="panel panel--attention">
        <div class="action__head">
            <div>
                <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($pendingAttention->kind->value)) ?>"><?= TemplateRenderer::escape(Presentation::label($pendingAttention->kind->value)) ?></span>
                <strong style="margin-left:8px">Attention required: stage <?= TemplateRenderer::escape($pendingAttention->stageId ?? 'execution') ?></strong>
            </div>
            <span class="small faint">created <?= TemplateRenderer::escape($pendingAttention->createdAt) ?></span>
        </div>

        <div class="workflow-brief__next" style="margin-top:12px">
            <strong>What this attention means</strong>
            <p class="small" style="margin:5px 0 0"><?= TemplateRenderer::escape($pendingAttention->message) ?></p>
        </div>

        <dl class="decision-facts">
            <div><dt>Attention</dt><dd><code><?= TemplateRenderer::escape($pendingAttention->id) ?></code></dd></div>
            <div><dt>Run</dt><dd><code><?= TemplateRenderer::escape($pendingAttention->runId) ?></code></dd></div>
            <?php if ($attentionDecision !== null): ?>
                <div><dt>Contract revision</dt><dd><?= (int) $attentionDecision->contractRevision ?></dd></div>
                <div><dt>Attempt</dt><dd><?= (int) $attentionDecision->attempt ?></dd></div>
                <div><dt>Repository HEAD</dt><dd><code><?= TemplateRenderer::escape($attentionDecision->headCommit === null ? 'unavailable' : substr($attentionDecision->headCommit, 0, 12)) ?></code></dd></div>
                <div><dt>Scope</dt><dd><?php foreach ($attentionDecision->scope as $path): ?><code class="decision-facts__chip"><?= TemplateRenderer::escape($path) ?></code><?php endforeach; ?></dd></div>
            <?php endif; ?>
        </dl>
        <p class="small note">Resolving records a human decision in agent-loop, bound to exactly this run, Contract revision, plan, attempt and HEAD. The stage then resumes in a new attempt and receives your response. Agents cannot resolve their own Attention.</p>

        <?php if ($attentionDecision !== null && in_array(WorkflowAttentionProjection::RESOLVE, $attentionDecision->actions, true)): ?>
        <form class="form" method="post" data-workflow-approval-form data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/attention" style="margin-top:12px">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <input type="hidden" name="attention_id" value="<?= TemplateRenderer::escape($pendingAttention->id) ?>">
            <input type="hidden" name="revision" value="<?= (int) $attentionDecision->contractRevision ?>">
            <input type="hidden" name="execution_plan_digest" value="<?= TemplateRenderer::escape($attentionDecision->executionPlanDigest) ?>">
            <input type="hidden" name="attempt" value="<?= (int) $attentionDecision->attempt ?>">
            <input type="hidden" name="candidate_revision" value="<?= TemplateRenderer::escape($attentionDecision->candidateRevision) ?>">
            <input type="hidden" name="head_commit" value="<?= TemplateRenderer::escape($attentionDecision->headCommit ?? '') ?>">
            <label class="field field--wide"><span>Response for the resumed stage · optional</span>
                <textarea name="response" maxlength="4000" rows="3" placeholder="e.g. Use the existing helper instead of adding a new one; keep the public API unchanged."></textarea>
            </label>
            <div class="form__row">
                <label class="field"><span>Resolved by</span><input required maxlength="200" name="actor" autocomplete="name" placeholder="who is resolving" data-remember-actor></label>
                <button class="btn btn--primary" type="submit">Resolve Attention</button>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
        <?php else: ?>
        <p class="note">agent-loop does not currently project a resolvable decision for this Attention, for example because the Contract revision changed since the stage paused. Nothing can be recorded here; follow the canonical next action instead.</p>
        <?php endif; ?>
    </section>
<?php elseif ($decisions->allows(WorkflowHumanDecisionProjection::APPROVE_CONTRACT) && $contract !== null): ?>
    <h2 class="eyebrow">Human decision required</h2>
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
            <?php if ($headCommit !== null): ?><input type="hidden" name="head_commit" value="<?= TemplateRenderer::escape($headCommit) ?>"><?php endif; ?>
            <p class="note" style="margin:0 0 10px">This records human authority over this exact Contract revision<?= $headCommit !== null ? ' at HEAD <code>' . TemplateRenderer::escape(substr($headCommit, 0, 12)) . '</code>' : '' ?>. It does not approve implementation, validation, review, or Learning.</p>
            <div class="form__row">
                <label class="field"><span>Approver</span><input required maxlength="200" name="actor" autocomplete="name" placeholder="who is approving" data-remember-actor></label>
                <button class="btn btn--primary" type="submit">Approve Contract</button>
                <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/contract">Revise before approving</a>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
        <?php if ($decisions->allows(WorkflowHumanDecisionProjection::REJECT_CONTRACT)): ?>
        <details class="decision-reject">
            <summary>Reject this revision instead</summary>
            <form class="form" method="post" data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/reject" style="margin-top:10px">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <input type="hidden" name="return_to" value="progress">
                <input type="hidden" name="revision" value="<?= (int) $contract->revision ?>">
                <?php if ($headCommit !== null): ?><input type="hidden" name="head_commit" value="<?= TemplateRenderer::escape($headCommit) ?>"><?php endif; ?>
                <p class="note" style="margin:0 0 10px">agent-loop records who rejected revision <?= (int) $contract->revision ?> and why. The rejected revision can never authorize work; the agent is routed to propose a new revision that addresses your reason.</p>
                <label class="field field--wide"><span>Reason · tells the agent what to change</span><textarea required maxlength="4000" rows="3" name="reason"></textarea></label>
                <div class="form__row">
                    <label class="field"><span>Rejected by</span><input required maxlength="200" name="actor" autocomplete="name" data-remember-actor></label>
                    <button class="btn btn--danger" type="submit">Reject revision <?= (int) $contract->revision ?></button>
                </div>
                <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
            </form>
        </details>
        <?php endif; ?>
    </section>
<?php elseif ($decisions->allows(WorkflowHumanDecisionProjection::ACKNOWLEDGE_REVIEW) && $decisions->reviewReportSha256 !== null): ?>
    <h2 class="eyebrow">Human review acknowledgement</h2>
    <section class="panel panel--attention">
        <div class="action__head">
            <strong>Review the exact report before acknowledging</strong>
            <span class="pill pill--attention">Owner decision available</span>
        </div>
        <p class="note">This decision is bound to the current report's SHA-256. Open the full review to inspect its findings; acknowledging does not declare the implementation correct or waive other workflow gates.</p>
        <p class="mono small" style="overflow-wrap:anywhere"><strong>Report SHA-256:</strong> <?= TemplateRenderer::escape($decisions->reviewReportSha256) ?></p>
        <details class="review-frame" open>
            <summary>agent-loop review page <a class="small" href="<?= TemplateRenderer::escape($reviewUrl) ?>" target="_blank" rel="noopener">open in new tab ↗</a></summary>
            <iframe title="agent-loop human review page" src="<?= TemplateRenderer::escape($reviewUrl) ?>" sandbox="allow-scripts" loading="lazy"></iframe>
            <p class="small faint">Rendered by agent-loop in an isolated sandbox. It is a reading aid, not authority; only the form below records a decision.</p>
        </details>
        <form class="form" method="post" data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/review-ack">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <input type="hidden" name="report_sha256" value="<?= TemplateRenderer::escape($decisions->reviewReportSha256) ?>">
            <div class="form__row">
                <label class="field"><span>Reviewed by</span><input required maxlength="200" name="actor" autocomplete="name" data-remember-actor></label>
                <button class="btn btn--primary" type="submit">Acknowledge exact report</button>
                <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/work">Inspect work &amp; review</a>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
    </section>
<?php elseif ($decisions->allows(WorkflowHumanDecisionProjection::RECORD_LEARNING)): ?>
    <h2 class="eyebrow">Learning disposition</h2>
    <section class="panel panel--attention">
        <strong>Record the outcome of this run</strong>
        <p class="note">Choose from the currently supported owner actions. No durable learning means there was no reusable finding; a follow-up requires a concrete reference. This is not a substitute for validation or review.</p>
        <form class="form" method="post" data-workflow-decision-form action="/task/<?= TemplateRenderer::escape($card->id) ?>/learning">
            <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
            <input type="hidden" name="return_to" value="progress">
            <input type="hidden" name="decision" value="no_durable_learning">
            <h3>No durable learning</h3>
            <div class="form__row">
                <label class="field"><span>Decided by</span><input required maxlength="200" name="actor" autocomplete="name" data-remember-actor></label>
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
                <label class="field"><span>Decided by</span><input required maxlength="200" name="actor" autocomplete="name" data-remember-actor></label>
                <label class="field"><span>Follow-up reference</span><input required maxlength="500" name="follow_up_ref"></label>
                <label class="field field--wide"><span>Reason</span><textarea required maxlength="2000" name="reason"></textarea></label>
                <button class="btn" type="submit">Record follow-up</button>
            </div>
            <p class="small note" data-workflow-approval-status aria-live="polite" hidden></p>
        </form>
        <p class="small note">Validated Finding content still belongs to the Learning owner. Use the full task decision page for any other projected action.</p>
    </section>
<?php elseif ($decisions->actions !== []): ?>
    <h2 class="eyebrow">Human decision available</h2>
    <section class="panel panel--attention">
        <p class="note">The owner projects another recordable decision. Its details are available on the full task page; this cockpit will not invent a generic approval.</p>
        <a class="btn btn--primary" href="/task/<?= TemplateRenderer::escape($card->id) ?>#decision">Open exact owner decision</a>
    </section>
<?php endif; ?>

<?php if ($attentionHistory !== []): ?>
    <details class="panel attention-history">
        <summary><strong>Attention history</strong> <span class="small faint">· <?= count($attentionHistory) ?> recorded by agent-loop</span></summary>
        <ol class="attention-history__list">
            <?php foreach (array_reverse($attentionHistory) as $resolution): ?>
                <li>
                    <div class="action__head">
                        <span><strong><?= TemplateRenderer::escape($resolution->stageId) ?></strong> <span class="small faint">attempt <?= (int) $resolution->attempt ?> · revision <?= (int) $resolution->contractRevision ?></span></span>
                        <span class="small faint"><?= TemplateRenderer::escape($resolution->actor) ?> · <?= TemplateRenderer::escape($resolution->resolvedAt) ?></span>
                    </div>
                    <?php if ($resolution->response !== null): ?><blockquote class="attention-history__response"><?= TemplateRenderer::escape($resolution->response) ?></blockquote><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </details>
<?php endif; ?>
