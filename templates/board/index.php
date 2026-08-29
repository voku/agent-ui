<?php
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{board: BoardSnapshot} $model */
$board = $model['board'];
$title = 'Board · ' . $board->projectPrefix . ' · agent-ui';
$nav = 'board';
$projectLabel = $board->projectPrefix;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <h1>Board</h1>
    <p class="lede">Lane order, card fields and status vocabulary are agent-kanban's semantics.
        This page lays them out and adds none of its own.</p>
</div>

<?php if ($board->cards === []): ?>
<section class="panel">
    <p class="empty">This board has no cards yet, so every lane below is empty.</p>
    <p class="note">agent-kanban owns card creation. From the project root:
        <code>vendor/bin/agent-loop board card create <?= TemplateRenderer::escape($board->projectPrefix) ?>-1 --title="…"</code>.
        agent-ui reads the board; it never writes to it.</p>
</section>
<?php endif; ?>

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
                        <?php if ($card->priority !== null): ?>
                            <span class="faint" style="font-size:11px">P<?= (int) $card->priority ?></span>
                        <?php endif; ?>
                        <?php if ($card->assignee !== null && $card->assignee !== ''): ?>
                            <span class="faint" style="font-size:11px"><?= TemplateRenderer::escape($card->assignee) ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
