<?php
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{card: CardSnapshot, workflow: WorkflowSnapshot, human_decisions: WorkflowHumanDecisionProjection, csrf_token: string} $model */
$card = $model['card'];
$workflow = $model['workflow'];
$decisions = $model['human_decisions'];
$csrf = $model['csrf_token'];
$title = $card->id . ' · Agent UI';
require __DIR__ . '/../layout/header.php';
?>
<h1><?= TemplateRenderer::escape($card->id) ?> · <?= TemplateRenderer::escape($card->title) ?></h1>
<p><?= TemplateRenderer::escape($card->summary) ?></p>
<div class="grid">
<section class="panel"><h2>Board</h2><dl><dt>Lane</dt><dd><?= TemplateRenderer::escape($card->lane) ?></dd><dt>Status</dt><dd><?= TemplateRenderer::escape($card->status) ?></dd><dt>Assignee</dt><dd><?= TemplateRenderer::escape($card->assignee ?? 'unassigned') ?></dd></dl></section>
<section class="panel"><h2>Workflow authority</h2><dl><dt>Run</dt><dd><?= TemplateRenderer::escape($workflow->runId) ?></dd><dt>State</dt><dd class="status"><?= TemplateRenderer::escape($workflow->state) ?></dd><dt>Mode</dt><dd><?= TemplateRenderer::escape($workflow->mode) ?></dd></dl></section>
</div>
<section class="panel <?= $workflow->nextActionKind === 'decision_required' ? 'attention' : '' ?>"><h2>Canonical next action</h2><p><strong><?= TemplateRenderer::escape($workflow->nextActionKind) ?></strong></p><pre><?= TemplateRenderer::escape($workflow->nextAction) ?></pre><p class="muted">Rendered from agent-loop. agent-ui does not calculate the next lifecycle step.</p></section>
<?php if ($workflow->disagreements !== []): ?><section class="panel danger"><h2>Disagreements</h2><?php foreach ($workflow->disagreements as $d): ?><p><strong><?= TemplateRenderer::escape($d['code']) ?></strong> [<?= TemplateRenderer::escape($d['owner']) ?>]: <?= TemplateRenderer::escape($d['message']) ?></p><?php endforeach; ?></section><?php endif; ?>

<?php if ($decisions->actions !== []): ?>
<section class="panel attention"><h2>Human decision</h2><p class="muted">These controls exist only because agent-loop currently projects the corresponding human action as recordable.</p>
<?php if ($decisions->allows(WorkflowHumanDecisionProjection::APPROVE_CONTRACT)): ?>
<form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/approve"><input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>"><label>Approver <input required maxlength="200" name="actor"></label> <button type="submit">Approve current Contract</button></form>
<?php endif; ?>
<?php if ($decisions->allows(WorkflowHumanDecisionProjection::ACKNOWLEDGE_REVIEW) && $decisions->reviewReportSha256 !== null): ?>
<form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/review-ack"><input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>"><input type="hidden" name="report_sha256" value="<?= TemplateRenderer::escape($decisions->reviewReportSha256) ?>"><p>Exact report: <code><?= TemplateRenderer::escape($decisions->reviewReportSha256) ?></code></p><label>Reviewer <input required maxlength="200" name="actor"></label> <button type="submit">Acknowledge exact review</button></form>
<?php endif; ?>
<?php if ($decisions->allows(WorkflowHumanDecisionProjection::RECORD_LEARNING)): ?>
<h3>No durable learning</h3><form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/learning"><input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>"><input type="hidden" name="decision" value="no_durable_learning"><label>Decided by <input required maxlength="200" name="actor"></label><br><label>Reason <textarea required maxlength="2000" name="reason"></textarea></label><br><button type="submit">Record no durable learning</button></form>
<h3>Follow-up required</h3><form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/learning"><input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>"><input type="hidden" name="decision" value="follow_up_required"><label>Decided by <input required maxlength="200" name="actor"></label><br><label>Reason <textarea required maxlength="2000" name="reason"></textarea></label><br><label>Follow-up reference <input required maxlength="500" name="follow_up_ref"></label><br><button type="submit">Record required follow-up</button></form>
<p class="muted">Validated findings are intentionally not faked here. A findings_recorded decision requires real Finding content through the Learning owner.</p>
<?php endif; ?>
</section>
<?php endif; ?>
<p><a class="button" href="/task/<?= TemplateRenderer::escape($card->id) ?>/evidence">Evidence & references</a></p>
<?php require __DIR__ . '/../layout/footer.php'; ?>
