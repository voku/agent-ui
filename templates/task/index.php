<?php
use voku\AgentLoop\Workflow\Transparency\ContextCoverage;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerSnapshot;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{card: CardSnapshot, workflow: WorkflowSnapshot, human_decisions: WorkflowHumanDecisionProjection, runner: RunnerSnapshot, context_explanation: ContextExplanationSnapshot, context_coverage: ContextCoverage, csrf_token: string} $model */
$card = $model['card'];
$workflow = $model['workflow'];
$decisions = $model['human_decisions'];
$runner = $model['runner'];
$context = $model['context_explanation'];
$contextCoverage = $model['context_coverage'];
$csrf = $model['csrf_token'];
$contextOmittedCount = 0;
foreach ($contextCoverage->omitted as $omission) {
    $contextOmittedCount += $omission->count;
}
$contextIntegrityProblem = $context->explanation?->hasIntegrityFailures() ?? false;
$contextTone = $context->status === ContextExplanationSnapshot::INVALID || $contextIntegrityProblem
    ? 'blocked'
    : ($context->status === ContextExplanationSnapshot::MISSING ? 'attention' : 'ok');
$title = $card->id . ' · ' . $card->title . ' · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><?= TemplateRenderer::escape($card->lane) ?></p>
<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($card->id) ?></span>
    <h1><?= TemplateRenderer::escape($card->title) ?></h1>
    <?php if ($card->summary !== ''): ?><p class="lede"><?= TemplateRenderer::escape($card->summary) ?></p><?php endif; ?>
</div>

<?php if ($workflow->references !== []): ?>
    <p class="eyebrow">Owner references</p>
    <section class="panel">
        <div class="refs">
            <?php foreach ($workflow->references as $name => $reference): ?>
                <?php $state = Presentation::referenceState($reference); ?>
                <div class="ref ref--<?= TemplateRenderer::escape(Presentation::tone($state)) ?>" title="<?= TemplateRenderer::escape((string) $name . ': ' . Presentation::label($state) . ' — owned by ' . (Presentation::referenceOwner($reference) ?? 'unknown')) ?>">
                    <span class="ref__name"><?= TemplateRenderer::escape(str_replace('_', ' ', (string) $name)) ?></span>
                    <span class="ref__state"><?= TemplateRenderer::escape(Presentation::label($state)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="note">Each mark is the state its owning package reports for that artifact. agent-ui does not derive them.</p>
    </section>
<?php endif; ?>

<p class="eyebrow">Current state</p>
<section class="panel action">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($workflow->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($workflow->state)) ?></span>
        <span class="small faint">mode <?= TemplateRenderer::escape($workflow->mode) ?> · run <span class="mono"><?= TemplateRenderer::escape($workflow->runId) ?></span></span>
    </div>
    <h2 style="margin-top:14px">Canonical next action</h2>
    <p class="action__hint"><?= TemplateRenderer::escape(Presentation::nextActionKindHint($workflow->nextActionKind)) ?></p>
    <div class="codeblock">
        <pre id="next-action"><?= TemplateRenderer::escape($workflow->nextAction) ?></pre>
        <button type="button" class="copy" data-copy-target="next-action">Copy</button>
    </div>
    <p class="note">Rendered from agent-loop. agent-ui does not calculate the next lifecycle step.</p>
</section>

<p class="eyebrow">Context &amp; constraints</p>
<section class="panel<?= $contextTone === 'blocked' ? ' panel--danger' : ($contextTone === 'attention' ? ' panel--attention' : '') ?>">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape($contextTone) ?>"><?= TemplateRenderer::escape($contextIntegrityProblem ? 'integrity problem' : $context->status) ?></span>
        <strong>What shaped this coding session?</strong>
    </div>
    <?php if ($context->explanation !== null): ?>
        <dl class="kv" style="margin-top:12px">
            <dt>Hard constraints</dt><dd><?= count($context->explanation->constraints) ?></dd>
            <dt>Guidance selected</dt><dd><?= $context->selectedGuidanceCount() ?></dd>
            <dt>Guidance excluded</dt><dd><?= $context->excludedGuidanceCount() ?></dd>
            <dt>Inputs skipped</dt><dd><?= count($contextCoverage->skipped) ?></dd>
            <dt>Budget omitted</dt><dd><?= $contextOmittedCount ?></dd>
        </dl>
        <p class="note">Counts come from persisted Recall selection plus agent-loop's typed context coverage. “Omitted” never means “irrelevant”.</p>
    <?php else: ?>
        <p class="muted"><?= TemplateRenderer::escape($context->problem ?? 'Persisted context explanation unavailable.') ?></p>
        <p class="note">Unavailable context is not rendered as an empty or unconstrained session.</p>
    <?php endif; ?>
    <p style="margin:14px 0 0"><a class="btn btn--primary" href="/task/<?= TemplateRenderer::escape($card->id) ?>/context">Explain context &amp; constraints →</a></p>
</section>

<?php if ($workflow->disagreements !== []): ?>
    <p class="eyebrow">Disagreements between owners</p>
    <section class="panel panel--danger stack">
        <?php foreach ($workflow->disagreements as $d): ?>
            <div>
                <p class="mono small" style="margin:0;color:var(--blocked)"><?= TemplateRenderer::escape($d['code']) ?></p>
                <p style="margin:2px 0 0"><?= TemplateRenderer::escape($d['message']) ?></p>
                <p class="note" style="margin-top:2px">owner: <?= TemplateRenderer::escape($d['owner']) ?></p>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($decisions->actions !== []): ?>
    <p class="eyebrow">Your decision</p>
    <section class="panel panel--attention">
        <p class="note" style="margin:0 0 14px">These controls exist only because agent-loop currently projects the
            corresponding human action as recordable.</p>

        <?php if ($decisions->allows(WorkflowHumanDecisionProjection::APPROVE_CONTRACT)): ?>
            <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/approve">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <h3>Approve the current Contract</h3>
                <p class="note" style="margin:0 0 10px">Approval records human authority over the scope, and nothing else.
                    It does not approve code, validation, or Learning.</p>
                <div class="form__row">
                    <label class="field"><span>Approver</span><input required maxlength="200" name="actor" autocomplete="name" placeholder="who is approving"></label>
                    <button class="btn btn--primary" type="submit">Approve Contract</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($decisions->allows(WorkflowHumanDecisionProjection::ACKNOWLEDGE_REVIEW) && $decisions->reviewReportSha256 !== null): ?>
            <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/review-ack">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <input type="hidden" name="report_sha256" value="<?= TemplateRenderer::escape($decisions->reviewReportSha256) ?>">
                <h3>Acknowledge the exact review report</h3>
                <p class="note" style="margin:0 0 8px">Binds you to this exact report digest, not to "the review" in general.</p>
                <p class="mono small" style="margin:0 0 10px;overflow-wrap:anywhere"><?= TemplateRenderer::escape($decisions->reviewReportSha256) ?></p>
                <div class="form__row">
                    <label class="field"><span>Reviewer</span><input required maxlength="200" name="actor" autocomplete="name" placeholder="who reviewed it"></label>
                    <button class="btn btn--primary" type="submit">Acknowledge review</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($decisions->allows(WorkflowHumanDecisionProjection::RECORD_LEARNING)): ?>
            <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/learning">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <input type="hidden" name="decision" value="no_durable_learning">
                <h3>Record: no durable learning</h3>
                <div class="form__row">
                    <label class="field"><span>Decided by</span><input required maxlength="200" name="actor" autocomplete="name"></label>
                </div>
                <div class="form__row" style="margin-top:10px">
                    <label class="field field--wide"><span>Reason</span><textarea required maxlength="2000" name="reason" placeholder="why this run taught nothing durable"></textarea></label>
                </div>
                <div class="form__row" style="margin-top:10px"><button class="btn" type="submit">Record no durable learning</button></div>
            </form>

            <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/learning">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <input type="hidden" name="decision" value="follow_up_required">
                <h3>Record: follow-up required</h3>
                <div class="form__row">
                    <label class="field"><span>Decided by</span><input required maxlength="200" name="actor" autocomplete="name"></label>
                    <label class="field"><span>Follow-up reference</span><input required maxlength="500" name="follow_up_ref" placeholder="LOOP-123 or an issue URL"></label>
                </div>
                <div class="form__row" style="margin-top:10px">
                    <label class="field field--wide"><span>Reason</span><textarea required maxlength="2000" name="reason"></textarea></label>
                </div>
                <div class="form__row" style="margin-top:10px"><button class="btn" type="submit">Record required follow-up</button></div>
            </form>

            <p class="note">Validated findings are intentionally not offered here. A <span class="mono">findings_recorded</span>
                decision requires real Finding content through the Learning owner.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<p class="eyebrow">Execution</p>
<section class="panel">
    <p class="note" style="margin:0 0 14px">The same governed workflow, two ways of doing the work.
        agent-loop owns workflow, approval, validation, review and Learning in both.</p>
    <div class="modes">
        <div class="mode mode--primary">
            <h3>Coding-agent session</h3>
            <p class="small muted">A coding agent performs the host-native implementation work. agent-loop remains
                workflow authority throughout.</p>
            <p style="margin:14px 0 0"><a class="btn btn--primary" href="/task/<?= TemplateRenderer::escape($card->id) ?>/handoff">Open governed handoff →</a></p>
        </div>
        <div class="mode">
            <h3>Managed runner</h3>
            <?php if (!$runner->installed): ?>
                <p class="small muted"><span class="mono">agent-loop-runner</span> is optional and is not installed in
                    this runtime. agent-loop works without it, and so does this page.</p>
            <?php elseif ($runner->diagnostic !== null): ?>
                <p class="small muted">Runner installed; no managed projection is available for this task yet.</p>
                <div class="codeblock"><pre><?= TemplateRenderer::escape($runner->diagnostic) ?></pre></div>
            <?php else: ?>
                <div class="split">
                    <div>
                        <p class="provenance provenance--authority">agent-loop authority</p>
                        <dl class="kv">
                            <dt>Profile</dt><dd><?= TemplateRenderer::escape($runner->profile ?? 'unknown') ?></dd>
                            <dt>Stage</dt><dd><?= TemplateRenderer::escape($runner->currentStageId ?? 'none pending') ?></dd>
                            <dt>Attempt</dt><dd><?= TemplateRenderer::escape((string) ($runner->currentAttempt ?? 0)) ?></dd>
                            <dt>Attention</dt><dd><?= TemplateRenderer::escape($runner->attentionId ?? 'none') ?></dd>
                        </dl>
                    </div>
                    <div>
                        <p class="provenance provenance--observation">runner observation</p>
                        <dl class="kv">
                            <dt>Host</dt><dd><?= TemplateRenderer::escape($runner->hostId ?? 'not observed') ?></dd>
                            <dt>Process</dt><dd><?= TemplateRenderer::escape($runner->observationStatus ?? 'none') ?></dd>
                        </dl>
                        <p class="note">A process exit is an observation. Only agent-loop accepts it as a transition.</p>
                    </div>
                </div>
                <div class="btn-row">
                    <?php foreach ([
                        ['run', 'Run', $runner->allowRun],
                        ['resume', 'Resume', $runner->allowResume],
                        ['cancel', 'Cancel', $runner->allowCancel],
                    ] as [$control, $label, $allowed]): ?>
                        <?php if ($allowed): ?>
                            <form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/runner/<?= TemplateRenderer::escape($control) ?>" style="margin:0">
                                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                                <button class="btn" type="submit"><?= TemplateRenderer::escape($label) ?></button>
                            </form>
                        <?php else: ?>
                            <button class="btn" type="button" disabled><?= TemplateRenderer::escape($label) ?></button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <p class="note">Enabled only where agent-loop-runner reports the control as legal right now.
                    Run and Resume block the current request: a single-process development server cannot service its
                    own Cancel while blocked, so Cancel is useful from another session observing the owned process.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="btn-row">
    <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/context">Context &amp; constraints</a>
    <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/evidence">Evidence &amp; audit</a>
    <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>/history">History</a>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
