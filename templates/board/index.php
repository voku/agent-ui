<?php
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{board: BoardSnapshot} $model */
$board=$model['board']; $title='Board · ' . $board->projectPrefix; require __DIR__ . '/../layout/header.php';
?>
<h1>Board</h1><p class="muted">Rendered from agent-kanban-owned card/config semantics.</p><div class="grid">
<?php foreach ($board->lanes as $lane): ?><section class="lane panel"><h2><?= TemplateRenderer::escape($lane) ?></h2>
<?php foreach ($board->cards as $card): if ($card->lane !== $lane) continue; ?><article class="card"><strong><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a></strong><p><?= TemplateRenderer::escape($card->title) ?></p><small><?= TemplateRenderer::escape($card->status) ?><?= $card->priority !== null ? ' · P' . $card->priority : '' ?></small></article><?php endforeach; ?></section><?php endforeach; ?>
</div><?php require __DIR__ . '/../layout/footer.php'; ?>
