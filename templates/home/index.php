<?php

use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLoop\Init\RepositorySetupProjection;
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{
 *     board: BoardSnapshot,
 *     attention: list<WorkflowSnapshot>,
 *     work: list<WorkflowSnapshot>,
 *     total_work_count?: int,
 *     setup: RepositorySetupProjection|null,
 *     setup_error: string|null,
 *     learning: LearningOverview|null,
 *     learning_error: string|null,
 *     map_readiness: MapReadinessSnapshot|null,
 *     runner_installed: bool,
 *     active_runner_tasks: list<array{taskId: string, title: string, stage: ?string}>,
 * } $model */
$board = $model['board'];
$attention = $model['attention'];
$work = $model['work'];
$totalWorkCount = (int) ($model['total_work_count'] ?? count($work));
$setup = $model['setup'];
$setupError = $model['setup_error'];
$learning = $model['learning'];
$learningError = $model['learning_error'];
$mapReadiness = $model['map_readiness'] ?? null;
$runnerInstalled = (bool) ($model['runner_installed'] ?? false);
$activeRunnerTasks = $model['active_runner_tasks'] ?? [];

$title = 'Overview · ' . $board->projectPrefix . ' · agent-ui';
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
        <h1><?= TemplateRenderer::escape($board->projectPrefix) ?> · Overview</h1>
        <p class="lede">What needs you, what is in flight, and what each task is waiting for — as agent-loop projects it.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="btn btn--primary" href="/board/new">+ New task</a>
        <a class="btn" href="/board">Open board</a>
    </div>
</div>

<p class="eyebrow" id="needs-you">Needs you</p>
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

<?php
/*
 * Current work is grouped by agent-loop's own kind for the next action.
 *
 * The first attempt grouped by the next-action sentence, which looked right and
 * was useless: every template carries its own task id, so no two tasks ever
 * shared a string and the page grew a heading per task instead of shrinking.
 * `next_action_kind` is the classification Loop already publishes, so it groups
 * what genuinely is the same kind of waiting.
 *
 * The exact commands are not summarised away - they sit under each group's
 * disclosure, verbatim, reachable by keyboard and without JavaScript.
 */
$workByKind = [];
foreach ($work as $item) {
    $workByKind[$item->nextActionKind][] = $item;
}
?>
<p class="eyebrow">Current work</p>
<section class="panel">
    <?php if ($work === []): ?>
        <p class="empty">No governed task snapshot is currently available.</p>
    <?php else: ?>
        <?php foreach ($workByKind as $kind => $items): ?>
            <div class="workgroup">
                <p class="provenance provenance--authority">
                    agent-loop · <?= TemplateRenderer::escape(Presentation::label((string) $kind)) ?>
                    <span class="workgroup__count"><?= count($items) ?> <?= count($items) === 1 ? 'task' : 'tasks' ?></span>
                </p>
                <p class="workgroup__hint"><?= TemplateRenderer::escape(Presentation::nextActionKindHint((string) $kind)) ?></p>
                <ul class="workgroup__tasks">
                    <?php foreach ($items as $item): ?>
                        <li>
                            <a href="/task/<?= TemplateRenderer::escape($item->taskId) ?>">
                                <span class="workgroup__id"><?= TemplateRenderer::escape($item->taskId) ?></span>
                                <span class="workgroup__title"><?= TemplateRenderer::escape($titles[$item->taskId] ?? $item->taskId) ?></span>
                            </a>
                            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($item->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($item->state)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <details class="workgroup__commands">
                    <summary>Canonical next action for <?= count($items) ?> <?= count($items) === 1 ? 'task' : 'tasks' ?></summary>
                    <?php foreach ($items as $item): ?>
                        <p class="workgroup__command-id"><?= TemplateRenderer::escape($item->taskId) ?></p>
                        <pre class="workgroup__action"><?= TemplateRenderer::escape($item->nextAction) ?></pre>
                        <?php $cmdRef = Presentation::commandReference($item->nextAction); ?>
                        <?php if ($cmdRef !== null): ?>
                            <p class="small note" style="margin:2px 0 8px"><a href="/commands#<?= TemplateRenderer::escape($cmdRef) ?>">View command reference: <code><?= TemplateRenderer::escape($cmdRef) ?></code> →</a></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </details>
            </div>
        <?php endforeach; ?>
        <?php if ($totalWorkCount > count($work)): ?>
            <p class="note" style="margin-top:12px">Showing <?= count($work) ?> of <?= $totalWorkCount ?> active tasks. <a href="/board">View all tasks on the Board →</a></p>
        <?php endif; ?>
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

<?php
/*
 * The supporting surfaces, reduced to one line each. They explain what a page
 * may not be able to answer; none of them is work. Lane-name heuristics and a
 * second "attention" count used to live here and are gone on purpose: the
 * board's lanes are agent-kanban's, and "Needs you" above is the one place a
 * waiting human decision is listed.
 */
?>
<p class="eyebrow">Supporting tools</p>
<section class="panel">
    <ul class="tool-status">
        <li>
            <span class="tool-status__name">Code Map</span>
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($mapReadiness?->status ?? 'missing')) ?>"><?= TemplateRenderer::escape(Presentation::label($mapReadiness?->status ?? 'missing')) ?></span>
            <span class="small muted"><?= (int) ($mapReadiness?->fileCount ?? 0) ?> files · <?= (int) ($mapReadiness?->symbolCount ?? 0) ?> symbols</span>
            <a class="small" href="/map">Map →</a>
        </li>
        <li>
            <span class="tool-status__name">Loop Runner</span>
            <span class="pill pill--<?= $activeRunnerTasks !== [] ? 'ok' : ($runnerInstalled ? 'neutral' : 'muted') ?>"><?= $activeRunnerTasks !== [] ? 'running' : ($runnerInstalled ? 'idle' : 'not installed') ?></span>
            <span class="small muted"><?= $activeRunnerTasks !== [] ? TemplateRenderer::escape(implode(', ', array_map(static fn(array $task): string => $task['taskId'] . ' active', $activeRunnerTasks))) : ($runnerInstalled ? 'managed execution available' : 'CLI-only execution') ?></span>
            <a class="small" href="/setup">Setup →</a>
        </li>
        <li>
            <span class="tool-status__name">Setup</span>
            <?php if ($setupError !== null): ?>
                <span class="pill pill--danger">unavailable</span>
                <span class="small muted"><?= TemplateRenderer::escape($setupError) ?></span>
            <?php else: ?>
                <span class="small muted">host integration, assets and policy</span>
            <?php endif; ?>
            <a class="small" href="/setup">Inspect →</a>
        </li>
    </ul>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
