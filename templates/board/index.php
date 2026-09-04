<?php
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{board: BoardSnapshot, csrf_token: string, filter_query?: string, filter_status?: string, filter_priority?: string, filter_assignee?: string} $model */
$board = $model['board'];
$csrf = $model['csrf_token'];
$filterQuery = $model['filter_query'] ?? '';
$filterStatus = $model['filter_status'] ?? '';
$filterPriority = $model['filter_priority'] ?? '';
$filterAssignee = $model['filter_assignee'] ?? '';

$hasFilter = $filterQuery !== '' || $filterStatus !== '' || $filterPriority !== '' || $filterAssignee !== '';

$title = 'Board · ' . $board->projectPrefix . ' · agent-ui';
$nav = 'board';
$projectLabel = $board->projectPrefix;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
        <h1><?= TemplateRenderer::escape($board->title ?? 'Board') ?></h1>
        <p class="lede">Lane order, card fields and status vocabulary are agent-kanban's semantics.
            This page lays them out and adds none of its own.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn" href="/map">Browse Code Map →</a>
        <a class="btn btn--primary" href="/board/new<?= $board->id !== null ? '?board=' . rawurlencode($board->id) : '' ?>">+ New card</a>
    </div>
</div>

<?php if (count($board->boards) > 1): ?>
<nav class="board-switcher" aria-label="Boards">
    <?php foreach ($board->boards as $b): ?>
        <a href="/board?board=<?= rawurlencode($b->id) ?>"<?= $b->active ? ' aria-current="page"' : '' ?>>
            <span><?= TemplateRenderer::escape($b->title) ?></span>
            <span class="pill pill--muted" style="font-size:11px"><?= $b->cardCount ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="panel" style="margin-bottom:16px;padding:10px 14px">
    <form class="form" method="get" action="/board" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <?php if ($board->id !== null): ?>
            <input type="hidden" name="board" value="<?= TemplateRenderer::escape($board->id) ?>">
        <?php endif; ?>
        <div style="flex:1;min-width:200px">
            <label class="field">
                <span>Filter cards</span>
                <input name="q" value="<?= TemplateRenderer::escape($filterQuery) ?>" placeholder="Search by ID, title, summary, assignee…">
            </label>
        </div>
        <div style="min-width:130px">
            <label class="field">
                <span>Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="open"<?= $filterStatus === 'open' ? ' selected' : '' ?>>Open</option>
                    <option value="in_progress"<?= $filterStatus === 'in_progress' ? ' selected' : '' ?>>In Progress</option>
                    <option value="blocked"<?= $filterStatus === 'blocked' ? ' selected' : '' ?>>Blocked</option>
                    <option value="done"<?= $filterStatus === 'done' ? ' selected' : '' ?>>Done</option>
                </select>
            </label>
        </div>
        <div style="min-width:110px">
            <label class="field">
                <span>Priority</span>
                <select name="priority">
                    <option value="">All priorities</option>
                    <option value="1"<?= $filterPriority === '1' ? ' selected' : '' ?>>P1 - Highest</option>
                    <option value="2"<?= $filterPriority === '2' ? ' selected' : '' ?>>P2 - High</option>
                    <option value="3"<?= $filterPriority === '3' ? ' selected' : '' ?>>P3 - Medium</option>
                    <option value="4"<?= $filterPriority === '4' ? ' selected' : '' ?>>P4 - Low</option>
                    <option value="5"<?= $filterPriority === '5' ? ' selected' : '' ?>>P5 - Lowest</option>
                </select>
            </label>
        </div>
        <div style="display:flex;gap:6px">
            <button class="btn btn--primary" type="submit">Filter</button>
            <?php if ($hasFilter): ?>
                <a class="btn" href="/board<?= $board->id !== null ? '?board=' . rawurlencode($board->id) : '' ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if ($board->cards === []): ?>
<section class="panel">
    <p class="empty">This board has no cards yet, so every lane below is empty.</p>
    <p class="note">Create your first card with the <a href="/board/new<?= $board->id !== null ? '?board=' . rawurlencode($board->id) : '' ?>">+ New card</a> form or via CLI:
        <code>vendor/bin/agent-loop board card create <?= TemplateRenderer::escape($board->projectPrefix) ?>-1 --title="…"</code>.</p>
</section>
<?php endif; ?>

<?php
/** @var list<CardSnapshot> $filteredCards */
$filteredCards = [];
$qLower = mb_strtolower($filterQuery);
foreach ($board->cards as $card) {
    if ($filterStatus !== '' && mb_strtolower($card->status) !== mb_strtolower($filterStatus)) {
        continue;
    }
    if ($filterPriority !== '' && (string) $card->priority !== $filterPriority) {
        continue;
    }
    if ($filterQuery !== '') {
        $matches = str_contains(mb_strtolower($card->id), $qLower)
            || str_contains(mb_strtolower($card->title), $qLower)
            || str_contains(mb_strtolower($card->summary), $qLower)
            || str_contains(mb_strtolower($card->taskBrief), $qLower)
            || ($card->assignee !== null && str_contains(mb_strtolower($card->assignee), $qLower));
        if (!$matches) {
            continue;
        }
    }
    $filteredCards[] = $card;
}
?>

<?php if ($hasFilter && $filteredCards === [] && $board->cards !== []): ?>
<section class="panel">
    <p class="empty">No cards match the active filters.</p>
    <p class="note"><a href="/board<?= $board->id !== null ? '?board=' . rawurlencode($board->id) : '' ?>">Reset filters</a> to view all <?= count($board->cards) ?> cards.</p>
</section>
<?php endif; ?>

<div class="lanes">
    <?php foreach ($board->lanes as $lane): ?>
        <?php
        $laneCards = [];
        foreach ($filteredCards as $card) {
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
                <div class="card">
                    <a class="card__id" href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a>
                    <a class="card__title" href="/task/<?= TemplateRenderer::escape($card->id) ?>" style="text-decoration:none;color:inherit;font-weight:600"><?= TemplateRenderer::escape($card->title) ?></a>
                    <?php if ($card->summary !== ''): ?>
                        <p class="small faint" style="margin:4px 0 0;line-height:1.3"><?= TemplateRenderer::escape(mb_substr($card->summary, 0, 90)) ?><?= mb_strlen($card->summary) > 90 ? '…' : '' ?></p>
                    <?php endif; ?>
                    <div class="card__meta">
                        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($card->status)) ?>"><?= TemplateRenderer::escape($card->status) ?></span>
                        <?php if ($card->priority !== null): ?>
                            <span class="pill pill--muted" style="font-size:11px">P<?= (int) $card->priority ?></span>
                        <?php endif; ?>
                        <?php if ($card->assignee !== null && $card->assignee !== ''): ?>
                            <span class="faint" style="font-size:11px"><?= TemplateRenderer::escape($card->assignee) ?></span>
                        <?php endif; ?>
                        <?php if ($card->claimActor !== null && $card->claimActor !== ''): ?>
                            <span class="pill pill--attention" style="font-size:10px">👤 <?= TemplateRenderer::escape($card->claimActor) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($card->allowedTransitions !== []): ?>
                        <div style="margin-top:8px;padding-top:6px;border-top:1px solid var(--rule);display:flex;gap:4px;flex-wrap:wrap">
                            <?php foreach ($card->allowedTransitions as $targetLane): ?>
                                <form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/move" style="margin:0">
                                    <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                                    <input type="hidden" name="target_lane" value="<?= TemplateRenderer::escape($targetLane) ?>">
                                    <input type="hidden" name="return_to" value="/board<?= $board->id !== null ? '?board=' . rawurlencode($board->id) : '' ?>">
                                    <button class="btn btn--small" type="submit" style="font-size:10px;padding:2px 6px">→ <?= TemplateRenderer::escape($targetLane) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
