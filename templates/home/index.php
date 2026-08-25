<?php
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{board: BoardSnapshot, attention: list<WorkflowSnapshot>} $model */
$board = $model['board'];
$attention = $model['attention'];
$title = 'Overview · ' . $board->projectPrefix . ' · agent-ui';
$nav = 'home';
$projectLabel = $board->projectPrefix;
$titles = [];
foreach ($board->cards as $card) {
    $titles[$card->id] = $card->title;
}
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <h1><?= TemplateRenderer::escape($board->projectPrefix) ?> control plane</h1>
    <p class="lede">Workflow authority stays in agent-loop. This page shows what those owners
        currently report, and what they are waiting on you for.</p>
</div>

<p class="eyebrow">Needs your attention</p>
<section class="panel<?= $attention === [] ? '' : ' panel--attention' ?>">
    <?php if ($attention === []): ?>
        <p class="empty">Nothing is waiting on a human right now.</p>
    <?php else: ?>
        <?php foreach ($attention as $item): ?>
            <a class="attn" href="/task/<?= TemplateRenderer::escape($item->taskId) ?>">
                <span class="attn__head">
                    <span class="attn__id"><?= TemplateRenderer::escape($item->taskId) ?></span>
                    <span class="attn__title"><?= TemplateRenderer::escape($titles[$item->taskId] ?? $item->taskId) ?></span>
                    <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($item->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($item->state)) ?></span>
                </span>
                <span class="attn__why"><?= TemplateRenderer::escape(Presentation::nextActionKindHint($item->nextActionKind)) ?></span>
                <span class="attn__action"><?= TemplateRenderer::escape($item->nextAction) ?></span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<p class="eyebrow">Work</p>
<div class="lanes">
    <?php foreach ($board->lanes as $lane): ?>
        <?php
        $laneCards = [];
        foreach ($board->cards as $card) {
            if ($card->lane === $lane) {
                $laneCards[] = $card;
            }
        }
        ?>
        <section class="lane">
            <header class="lane__head">
                <span class="lane__name"><?= TemplateRenderer::escape($lane) ?></span>
                <span class="lane__count"><?= count($laneCards) ?></span>
            </header>
            <?php if ($laneCards === []): ?>
                <p class="empty small" style="margin:2px">empty</p>
            <?php endif; ?>
            <?php foreach ($laneCards as $card): ?>
                <a class="card" href="/task/<?= TemplateRenderer::escape($card->id) ?>">
                    <span class="card__id"><?= TemplateRenderer::escape($card->id) ?></span>
                    <span class="card__title"><?= TemplateRenderer::escape($card->title) ?></span>
                    <span class="card__meta">
                        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($card->status)) ?>"><?= TemplateRenderer::escape($card->status) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
