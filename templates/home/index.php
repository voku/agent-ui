<?php
use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLoop\Init\RepositorySetupProjection;
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{board: BoardSnapshot, attention: list<WorkflowSnapshot>, work: list<WorkflowSnapshot>, setup: RepositorySetupProjection|null, setup_error: string|null, learning: LearningOverview|null, learning_error: string|null} $model */
$board = $model['board'];
$attention = $model['attention'];
$work = $model['work'];
$setup = $model['setup'];
$setupError = $model['setup_error'];
$learning = $model['learning'];
$learningError = $model['learning_error'];
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
    <p class="lede">One cockpit, multiple owners. Workflow authority, setup state, Recall/Learning truth and repository observation stay visibly separate.</p>
</div>

<p class="eyebrow">Setup</p>
<section class="panel<?= $setupError !== null ? ' panel--danger' : '' ?>">
    <?php if ($setupError !== null): ?>
        <h2>Setup state unavailable</h2>
        <p class="muted"><?= TemplateRenderer::escape($setupError) ?></p>
    <?php elseif ($setup !== null): ?>
        <h2>Repository readiness</h2>
        <p>
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($setup->runtime?->status->value ?? 'unavailable')) ?>"><?= TemplateRenderer::escape($setup->runtime?->status->value ?? 'unavailable') ?></span>
            <?php if ($setup->host !== null): ?><span class="mono"><?= TemplateRenderer::escape($setup->host) ?></span><?php endif; ?>
        </p>
        <p class="small muted"><?= TemplateRenderer::escape($setup->nextAction ?? 'No repository-owned setup action is currently projected.') ?></p>
        <p><a href="/setup">Inspect setup and safe owner actions →</a></p>
    <?php endif; ?>
</section>

<p class="eyebrow">Needs you</p>
<section class="panel<?= $attention === [] ? '' : ' panel--attention' ?>">
    <?php if ($attention === []): ?>
        <p class="empty">No workflow decision is waiting on a human right now.</p>
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

<p class="eyebrow">Current work</p>
<section class="panel">
    <?php if ($work === []): ?>
        <p class="empty">No governed task snapshot is currently available.</p>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($work as $item): ?>
                <a class="attn" href="/task/<?= TemplateRenderer::escape($item->taskId) ?>">
                    <span class="attn__head">
                        <span class="attn__id"><?= TemplateRenderer::escape($item->taskId) ?></span>
                        <span class="attn__title"><?= TemplateRenderer::escape($titles[$item->taskId] ?? $item->taskId) ?></span>
                        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($item->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($item->state)) ?></span>
                    </span>
                    <span class="attn__action"><?= TemplateRenderer::escape($item->nextAction) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<p class="eyebrow">Knowledge</p>
<section class="panel<?= $learningError !== null ? ' panel--danger' : '' ?>">
    <?php if ($learningError !== null): ?>
        <h2>Learning state unavailable</h2>
        <p class="muted"><?= TemplateRenderer::escape($learningError) ?></p>
    <?php elseif ($learning !== null): ?>
        <h2>Durable Learning</h2>
        <div class="grid">
            <div><strong><?= count($learning->findingAttentionIds) ?></strong><br><span class="small muted">findings needing attention</span></div>
            <div><strong><?= count($learning->proposalAttentionIds) ?></strong><br><span class="small muted">proposals needing attention</span></div>
            <div><strong><?= count($learning->recentDurableGuidanceIds) ?></strong><br><span class="small muted">recent durable guidance records</span></div>
        </div>
        <p><a href="/knowledge">Browse Learning lineage →</a></p>
    <?php endif; ?>
</section>

<p class="eyebrow">Board</p>
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
