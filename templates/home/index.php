<?php
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{board: BoardSnapshot, attention: list<voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot>} $model */
$board = $model['board']; $attention = $model['attention']; $title='Agent UI · ' . $board->projectPrefix;
require __DIR__ . '/../layout/header.php';
?>
<h1><?= TemplateRenderer::escape($board->projectPrefix) ?></h1>
<p class="muted">Human control plane. Workflow authority remains in agent-loop; this page only presents owner state.</p>
<section class="panel"><h2>Needs your attention</h2>
<?php if ($attention === []): ?><p>No owner-projected human decision is currently waiting.</p><?php endif; ?>
<?php foreach ($attention as $item): ?><article class="card attention"><strong><a href="/task/<?= TemplateRenderer::escape($item->taskId) ?>"><?= TemplateRenderer::escape($item->taskId) ?></a></strong><p><?= TemplateRenderer::escape($item->nextAction) ?></p></article><?php endforeach; ?>
</section>
<h2>Work</h2><div class="grid">
<?php foreach ($board->lanes as $lane): ?><section class="lane panel"><h2><?= TemplateRenderer::escape($lane) ?></h2>
<?php foreach ($board->cards as $card): if ($card->lane !== $lane) continue; ?><article class="card"><strong><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a></strong><div><?= TemplateRenderer::escape($card->title) ?></div><small><?= TemplateRenderer::escape($card->status) ?></small></article><?php endforeach; ?></section><?php endforeach; ?>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
