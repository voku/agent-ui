<?php
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{card: CardSnapshot, workflow: WorkflowSnapshot} $model */
$card=$model['card']; $workflow=$model['workflow']; $title=$card->id . ' · Agent UI'; require __DIR__ . '/../layout/header.php';
?>
<h1><?= TemplateRenderer::escape($card->id) ?> · <?= TemplateRenderer::escape($card->title) ?></h1>
<p><?= TemplateRenderer::escape($card->summary) ?></p>
<div class="grid"><section class="panel"><h2>Board</h2><dl><dt>Lane</dt><dd><?= TemplateRenderer::escape($card->lane) ?></dd><dt>Status</dt><dd><?= TemplateRenderer::escape($card->status) ?></dd><dt>Assignee</dt><dd><?= TemplateRenderer::escape($card->assignee ?? 'unassigned') ?></dd></dl></section>
<section class="panel"><h2>Workflow authority</h2><dl><dt>Run</dt><dd><?= TemplateRenderer::escape($workflow->runId) ?></dd><dt>State</dt><dd class="status"><?= TemplateRenderer::escape($workflow->state) ?></dd><dt>Mode</dt><dd><?= TemplateRenderer::escape($workflow->mode) ?></dd></dl></section></div>
<section class="panel <?= $workflow->nextActionKind === 'decision_required' ? 'attention' : '' ?>"><h2>Canonical next action</h2><p><strong><?= TemplateRenderer::escape($workflow->nextActionKind) ?></strong></p><pre><?= TemplateRenderer::escape($workflow->nextAction) ?></pre><p class="muted">This is rendered from agent-loop. agent-ui does not calculate the next lifecycle step.</p></section>
<?php if ($workflow->disagreements !== []): ?><section class="panel danger"><h2>Disagreements</h2><?php foreach ($workflow->disagreements as $d): ?><p><strong><?= TemplateRenderer::escape($d['code']) ?></strong> [<?= TemplateRenderer::escape($d['owner']) ?>]: <?= TemplateRenderer::escape($d['message']) ?></p><?php endforeach; ?></section><?php endif; ?>
<p><a class="button" href="/task/<?= TemplateRenderer::escape($card->id) ?>/evidence">Evidence & references</a></p>
<?php require __DIR__ . '/../layout/footer.php'; ?>
