<?php

use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLoop\Init\RepositorySetupProjection;
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\Integration\AgentMap\MapGraphSnapshot;
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{
 *     board: BoardSnapshot,
 *     attention: list<WorkflowSnapshot>,
 *     work: list<WorkflowSnapshot>,
 *     setup: RepositorySetupProjection|null,
 *     setup_error: string|null,
 *     learning: LearningOverview|null,
 *     learning_error: string|null,
 *     map_readiness: MapReadinessSnapshot|null,
 *     graph: MapGraphSnapshot|null,
 *     runner_installed: bool,
 *     active_runner_tasks: list<array{taskId: string, title: string, stage: ?string}>,
 *     lane_counts: array<string, int>,
 * } $model */
$board = $model['board'];
$attention = $model['attention'];
$work = $model['work'];
$setup = $model['setup'];
$setupError = $model['setup_error'];
$learning = $model['learning'];
$learningError = $model['learning_error'];
$mapReadiness = $model['map_readiness'] ?? null;
$graph = $model['graph'] ?? null;
$runnerInstalled = (bool) ($model['runner_installed'] ?? false);
$activeRunnerTasks = $model['active_runner_tasks'] ?? [];
$laneCounts = $model['lane_counts'] ?? [];

$title = 'Developer Cockpit · ' . $board->projectPrefix . ' · agent-ui';
$nav = 'home';
$projectLabel = $board->projectPrefix;
$titles = [];
foreach ($board->cards as $card) {
    $titles[$card->id] = $card->title;
}
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:14px">
    <div>
        <h1><?= TemplateRenderer::escape($board->projectPrefix) ?> Developer Cockpit</h1>
        <p class="lede">Unified control plane across workflow authority, repository observation, autonomous execution, and durable learning.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="btn btn--primary" href="/board/new">+ New Kanban Task</a>
        <a class="btn" href="/map/graph">Architecture Graph</a>
        <a class="btn" href="/prompts">Prompt Workbench</a>
        <a class="btn" href="/setup">Setup &amp; Runtime</a>
    </div>
</div>

<p class="eyebrow">System Vitals</p>
<div class="grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px">
    <section class="panel panel--accent">
        <div class="action__head">
            <span class="small faint" style="font-weight:600">Code Map</span>
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($mapReadiness?->status ?? 'missing')) ?>">
                <?= TemplateRenderer::escape($mapReadiness?->status ?? 'missing') ?>
            </span>
        </div>
        <p style="margin:10px 0 4px;font-size:18px;font-weight:650">
            <?= (int) ($mapReadiness?->fileCount ?? 0) ?> <span class="small muted" style="font-weight:normal">files</span> · <?= (int) ($mapReadiness?->symbolCount ?? 0) ?> <span class="small muted" style="font-weight:normal">symbols</span>
        </p>
        <p class="small muted" style="margin:0">
            <?= count($graph?->availableRegions ?? []) ?> architecture regions
        </p>
        <p style="margin:10px 0 0"><a href="/map/graph" class="small">Explore architecture →</a></p>
    </section>

    <section class="panel">
        <div class="action__head">
            <span class="small faint" style="font-weight:600">Kanban Flow</span>
            <span class="pill pill--neutral"><?= count($board->cards) ?> cards</span>
        </div>
        <p style="margin:10px 0 4px;font-size:18px;font-weight:650">
            <?= (int) ($laneCounts['in_progress'] ?? ($laneCounts['In Progress'] ?? 0)) ?> <span class="small muted" style="font-weight:normal">active</span> · <?= (int) ($laneCounts['review'] ?? ($laneCounts['Review'] ?? 0)) ?> <span class="small muted" style="font-weight:normal">in review</span>
        </p>
        <p class="small muted" style="margin:0">
            <?= (int) ($laneCounts['done'] ?? ($laneCounts['Done'] ?? 0)) ?> completed tasks
        </p>
        <p style="margin:10px 0 0"><a href="/board" class="small">Open board →</a></p>
    </section>

    <section class="panel <?= $attention !== [] ? 'panel--attention' : '' ?>">
        <div class="action__head">
            <span class="small faint" style="font-weight:600">Attention Radar</span>
            <span class="pill pill--<?= $attention !== [] ? 'attention' : 'ok' ?>">
                <?= $attention !== [] ? count($attention) . ' waiting' : 'clear' ?>
            </span>
        </div>
        <p style="margin:10px 0 4px;font-size:18px;font-weight:650">
            <?= count($attention) ?> <span class="small muted" style="font-weight:normal"><?= count($attention) === 1 ? 'decision required' : 'decisions required' ?></span>
        </p>
        <p class="small muted" style="margin:0">
            <?= $attention !== [] ? 'Human authority requested' : 'No blockers in flight' ?>
        </p>
        <?php if ($attention !== []): ?>
            <p style="margin:10px 0 0"><a href="#needs-you" class="small" style="font-weight:600">Review blockers ↓</a></p>
        <?php else: ?>
            <p style="margin:10px 0 0"><span class="small faint">All autonomous steps clear</span></p>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="action__head">
            <span class="small faint" style="font-weight:600">Loop Runner</span>
            <span class="pill pill--<?= $activeRunnerTasks !== [] ? 'ok' : ($runnerInstalled ? 'neutral' : 'muted') ?>">
                <?= $activeRunnerTasks !== [] ? 'running' : ($runnerInstalled ? 'idle' : 'uninstalled') ?>
            </span>
        </div>
        <p style="margin:10px 0 4px;font-size:18px;font-weight:650">
            <?= $activeRunnerTasks !== [] ? count($activeRunnerTasks) : ($runnerInstalled ? 'Ready' : 'Manual') ?>
            <span class="small muted" style="font-weight:normal"><?= $activeRunnerTasks !== [] ? 'task(s) in flight' : ($runnerInstalled ? 'managed execution' : 'CLI-only') ?></span>
        </p>
        <p class="small muted" style="margin:0">
            <?= $activeRunnerTasks !== [] ? TemplateRenderer::escape($activeRunnerTasks[0]['taskId'] . ' active') : ($runnerInstalled ? 'Background runner available' : 'Install agent-loop-runner') ?>
        </p>
        <p style="margin:10px 0 0"><a href="/setup" class="small">Inspect runtime setup →</a></p>
    </section>
</div>

<?php if ($graph !== null && $graph->availableRegions !== []): ?>
    <p class="eyebrow">Architecture Pulse</p>
    <section class="panel">
        <div class="action__head">
            <div>
                <strong>Architecture Regions (<?= count($graph->availableRegions) ?>)</strong>
                <span class="small faint" style="margin-left:8px">Coupling boundaries from agent-map</span>
            </div>
            <a href="/map/graph" class="small">Explore interactive coupling graph →</a>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
            <a href="/map/graph" class="pill pill--accent" style="text-decoration:none">All regions (<?= count($graph->availableRegions) ?>)</a>
            <?php foreach (array_slice($graph->availableRegions, 0, 8) as $regionPill): ?>
                <a href="/map/graph?region=<?= rawurlencode($regionPill['id']) ?>" class="pill pill--neutral" style="text-decoration:none" title="<?= TemplateRenderer::escape($regionPill['label']) ?> (<?= $regionPill['fileCount'] ?> files)">
                    <?= TemplateRenderer::escape($regionPill['label']) ?> <span class="faint">(<?= $regionPill['fileCount'] ?>)</span>
                </a>
            <?php endforeach; ?>
            <?php if (count($graph->availableRegions) > 8): ?>
                <a href="/map/graph" class="pill pill--neutral faint" style="text-decoration:none">+<?= count($graph->availableRegions) - 8 ?> more…</a>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

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
<?php if (count($board->boards) > 1): ?>
    <div class="board-switcher" style="margin-bottom: 14px;">
        <?php foreach ($board->boards as $b): ?>
            <a href="/board?board=<?= rawurlencode($b->id) ?>"<?= $b->active ? ' aria-current="page"' : '' ?>>
                <span><?= TemplateRenderer::escape($b->title) ?></span>
                <span class="pill pill--muted" style="font-size:11px"><?= $b->cardCount ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($board->cards === []): ?>
    <section class="panel">
        <p class="empty">This board has no cards yet.</p>
        <p class="note">Cards are agent-kanban's to create. From the project root:
            <code>vendor/bin/agent-loop board card create <?= TemplateRenderer::escape($board->projectPrefix) ?>-1 --title="…"</code>.</p>
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
                    </span>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
<p style="margin-top:14px"><a href="/board">Open the full board →</a></p>
<?php require __DIR__ . '/../layout/footer.php'; ?>
