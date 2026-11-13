<?php
use voku\AgentUi\Feature\Board\BoardAction;
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/**
 * @var array{
 *     board: BoardSnapshot,
 *     cards: list<CardSnapshot>,
 *     workflow: array<string, WorkflowSnapshot|null>,
 *     runners?: array<string, RunnerSnapshot|null>,
 *     statuses: list<string>,
 *     kind_counts: array<string, int>,
 *     human_decision_count: int,
 *     pending_attention: array<string, \voku\AgentLoop\Execution\AttentionRequest>,
 *     attention_unavailable_ids: list<string>,
 *     disagreement_count: int,
 *     executing_count?: int,
 *     blocked_count?: int,
 *     csrf_token: string,
 *     filter_query: string,
 *     filter_status: string,
 *     filter_priority: string,
 *     filter_workflow: string
 * } $model
 */
$board = $model['board'];
$filteredCards = $model['cards'];
$workflow = $model['workflow'];
$runners = $model['runners'] ?? [];
$statuses = $model['statuses'];
$kindCounts = $model['kind_counts'];
$humanDecisionCount = $model['human_decision_count'];
$pendingAttention = $model['pending_attention'];
$attentionUnavailableIds = $model['attention_unavailable_ids'];
$disagreementCount = $model['disagreement_count'];
$executingCount = $model['executing_count'] ?? 0;
$blockedCount = $model['blocked_count'] ?? 0;
$csrf = $model['csrf_token'];
$filterQuery = $model['filter_query'];
$filterStatus = $model['filter_status'];
$filterPriority = $model['filter_priority'];
$filterWorkflow = $model['filter_workflow'];
$boardQuery = $board->id !== null ? '?board=' . rawurlencode($board->id) : '';
$workflowHref = static fn (string $value): string => '/board?' . http_build_query(array_filter(['board' => $board->id, 'workflow' => $value], static fn (?string $v): bool => $v !== null));

$hasFilter = $filterQuery !== '' || $filterStatus !== '' || $filterPriority !== '' || $filterWorkflow !== '';

$title = 'Board · ' . $board->projectPrefix . ' · agent-ui';
$nav = 'board';
$projectLabel = $board->projectPrefix;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
        <h1><?= TemplateRenderer::escape($board->title ?? 'Board') ?></h1>
        <p class="lede">Each card shows independently sourced signals side by side: <strong>Board</strong> is agent-kanban's lane and status,
            <strong>Workflow</strong> is agent-loop's lifecycle state and next action, and <strong>Execution</strong> is optional runner observations.
            This page lays them out and adds no semantics of its own.</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn" href="/map">Browse Code Map →</a>
        <a class="btn btn--primary" href="/board/new<?= TemplateRenderer::escape($boardQuery) ?>">+ New card</a>
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
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= TemplateRenderer::escape($status) ?>"<?= $filterStatus === $status ? ' selected' : '' ?>><?= TemplateRenderer::escape(Presentation::label($status)) ?></option>
                    <?php endforeach; ?>
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
        <div style="min-width:160px">
            <label class="field">
                <span>Workflow / Cockpit</span>
                <select name="workflow">
                    <option value="">Any next step</option>
                    <?php if ($humanDecisionCount > 0 || $filterWorkflow === BoardAction::FILTER_DECISION_REQUIRED): ?>
                        <option value="<?= BoardAction::FILTER_DECISION_REQUIRED ?>"<?= $filterWorkflow === BoardAction::FILTER_DECISION_REQUIRED ? ' selected' : '' ?>>Needs my decision</option>
                    <?php endif; ?>
                    <?php if ($executingCount > 0 || $filterWorkflow === BoardAction::FILTER_EXECUTING): ?>
                        <option value="<?= BoardAction::FILTER_EXECUTING ?>"<?= $filterWorkflow === BoardAction::FILTER_EXECUTING ? ' selected' : '' ?>>Currently executing</option>
                    <?php endif; ?>
                    <?php if ($blockedCount > 0 || $filterWorkflow === BoardAction::FILTER_BLOCKED): ?>
                        <option value="<?= BoardAction::FILTER_BLOCKED ?>"<?= $filterWorkflow === BoardAction::FILTER_BLOCKED ? ' selected' : '' ?>>Blocked / Interrupted</option>
                    <?php endif; ?>
                    <?php if ($disagreementCount > 0 || $filterWorkflow === BoardAction::FILTER_DISAGREEMENT): ?>
                        <option value="<?= BoardAction::FILTER_DISAGREEMENT ?>"<?= $filterWorkflow === BoardAction::FILTER_DISAGREEMENT ? ' selected' : '' ?>>Board and workflow disagree</option>
                    <?php endif; ?>
                    <?php foreach ($kindCounts as $kind => $count): ?>
                        <?php if ($kind === 'decision_required') { continue; } ?>
                        <option value="<?= TemplateRenderer::escape((string) $kind) ?>"<?= $filterWorkflow === (string) $kind ? ' selected' : '' ?>>Next: <?= TemplateRenderer::escape(Presentation::label((string) $kind)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <div style="display:flex;gap:6px">
            <button class="btn btn--primary" type="submit">Filter</button>
            <?php if ($hasFilter): ?>
                <a class="btn" href="/board<?= TemplateRenderer::escape($boardQuery) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if ($kindCounts !== [] || $disagreementCount > 0 || $executingCount > 0 || $blockedCount > 0 || $humanDecisionCount > 0 || $attentionUnavailableIds !== []): ?>
<nav class="board-summary" aria-label="Workflow summary">
    <?php $decisions = $humanDecisionCount; ?>
    <a class="board-summary__item<?= $decisions > 0 ? ' board-summary__item--attention' : '' ?>" href="<?= TemplateRenderer::escape($workflowHref(BoardAction::FILTER_DECISION_REQUIRED)) ?>"<?= $filterWorkflow === BoardAction::FILTER_DECISION_REQUIRED ? ' aria-current="true"' : '' ?>>
        <strong><?= $decisions ?></strong> <?= $attentionUnavailableIds !== [] ? 'confirmed waiting; Attention visibility incomplete' : 'waiting on a human decision' ?>
    </a>
    <?php if ($executingCount > 0 || $filterWorkflow === BoardAction::FILTER_EXECUTING): ?>
        <a class="board-summary__item" href="<?= TemplateRenderer::escape($workflowHref(BoardAction::FILTER_EXECUTING)) ?>"<?= $filterWorkflow === BoardAction::FILTER_EXECUTING ? ' aria-current="true"' : '' ?>>
            <strong><?= $executingCount ?></strong> actively executing
        </a>
    <?php endif; ?>
    <?php if ($blockedCount > 0 || $filterWorkflow === BoardAction::FILTER_BLOCKED): ?>
        <a class="board-summary__item board-summary__item--blocked" href="<?= TemplateRenderer::escape($workflowHref(BoardAction::FILTER_BLOCKED)) ?>"<?= $filterWorkflow === BoardAction::FILTER_BLOCKED ? ' aria-current="true"' : '' ?>>
            <strong><?= $blockedCount ?></strong> blocked / interrupted
        </a>
    <?php endif; ?>
    <a class="board-summary__item<?= $disagreementCount > 0 ? ' board-summary__item--blocked' : '' ?>" href="<?= TemplateRenderer::escape($workflowHref(BoardAction::FILTER_DISAGREEMENT)) ?>"<?= $filterWorkflow === BoardAction::FILTER_DISAGREEMENT ? ' aria-current="true"' : '' ?>>
        <strong><?= $disagreementCount ?></strong> board and workflow disagree
    </a>
    <?php foreach ($kindCounts as $kind => $count): ?>
        <?php if ($kind === 'decision_required') { continue; } ?>
        <a class="board-summary__item" href="<?= TemplateRenderer::escape($workflowHref((string) $kind)) ?>" title="<?= TemplateRenderer::escape(Presentation::nextActionKindHint((string) $kind)) ?>"<?= $filterWorkflow === (string) $kind ? ' aria-current="true"' : '' ?>>
            <strong><?= $count ?></strong> next: <?= TemplateRenderer::escape(Presentation::label((string) $kind)) ?>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($attentionUnavailableIds !== []): ?>
<section class="panel" role="status" aria-label="Attention availability" style="margin-bottom:16px">
    <p class="note" style="margin:0">
        <strong>Decision visibility incomplete.</strong>
        agent-loop Attention could not be verified for <?= count($attentionUnavailableIds) ?> card(s).
        Confirmed decisions are shown below, but the count is not authoritative until the owner projection is available.
        Affected cards:
        <?php foreach (array_slice($attentionUnavailableIds, 0, 5) as $unavailableId): ?>
            <a href="/task/<?= rawurlencode($unavailableId) ?>"><?= TemplateRenderer::escape($unavailableId) ?></a>
        <?php endforeach; ?>
    </p>
    <?php if (count($attentionUnavailableIds) > 5): ?>
        <details>
            <summary>Show <?= count($attentionUnavailableIds) - 5 ?> more affected cards</summary>
            <ul>
                <?php foreach (array_slice($attentionUnavailableIds, 5) as $unavailableId): ?>
                    <li><a href="/task/<?= rawurlencode($unavailableId) ?>"><?= TemplateRenderer::escape($unavailableId) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($board->cards === []): ?>
<section class="panel">
    <p class="empty">This board has no cards yet, so every lane below is empty.</p>
    <p class="note">Create your first card with the <a href="/board/new<?= TemplateRenderer::escape($boardQuery) ?>">+ New card</a> form or via CLI:
        <code>vendor/bin/agent-loop board card create <?= TemplateRenderer::escape($board->projectPrefix) ?>-1 --title="…"</code>.</p>
</section>
<?php endif; ?>


<?php if ($hasFilter && $filteredCards === [] && $board->cards !== []): ?>
<section class="panel">
    <p class="empty">No cards match the active filters.</p>
    <p class="note"><a href="/board<?= TemplateRenderer::escape($boardQuery) ?>">Reset filters</a> to view all <?= count($board->cards) ?> cards.</p>
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
                <?php
                $flow = $workflow[$card->id] ?? null;
                $runnerSnap = $runners[$card->id] ?? null;
                $cardClass = 'card';
                if ($flow !== null && $flow->disagreements !== []) {
                    $cardClass .= ' card--blocked';
                } elseif ($flow?->nextActionKind === 'decision_required' || isset($pendingAttention[$card->id])) {
                    $cardClass .= ' card--attention';
                }
                ?>
                <div class="<?= $cardClass ?>">
                    <a class="card__id" href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a>
                    <a class="card__title" href="/task/<?= TemplateRenderer::escape($card->id) ?>" style="text-decoration:none;color:inherit;font-weight:600"><?= TemplateRenderer::escape($card->title) ?></a>
                    <?php if ($card->summary !== ''): ?>
                        <p class="small faint" style="margin:4px 0 0;line-height:1.3"><?= TemplateRenderer::escape(mb_substr($card->summary, 0, 90)) ?><?= mb_strlen($card->summary) > 90 ? '…' : '' ?></p>
                    <?php endif; ?>
                    <?php if ($flow?->nextActionKind === 'decision_required' || isset($pendingAttention[$card->id])): ?>
                        <div style="margin-top:6px">
                            <a class="btn btn--small btn--attention" style="font-size:11px;padding:3px 8px;display:inline-flex;align-items:center;gap:4px" href="/task/<?= rawurlencode($card->id) ?><?= isset($pendingAttention[$card->id]) ? '#decision' : '/progress#ws-overview' ?>">Needs Human Decision</a>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($pendingAttention[$card->id])): ?>
                        <details class="small" style="margin-top:6px">
                            <summary>Execution Attention: <?= TemplateRenderer::escape(Presentation::label($pendingAttention[$card->id]->kind->value)) ?></summary>
                            <p><?= TemplateRenderer::escape($pendingAttention[$card->id]->message) ?></p>
                            <?php if ($flow === null): ?>
                                <p class="faint">Canonical next action unavailable. Inspect owner state before acting.</p>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                    <dl class="card__owners">
                        <dt>Board</dt>
                        <dd>
                            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($card->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($card->status)) ?></span>
                            <?php if ($card->priority !== null): ?>
                                <span class="pill pill--muted">P<?= (int) $card->priority ?></span>
                            <?php endif; ?>
                            <?php if ($card->claimActor !== null && $card->claimActor !== ''): ?>
                                <span class="pill pill--attention" title="Claimed on the board">👤 <?= TemplateRenderer::escape($card->claimActor) ?></span>
                            <?php elseif ($card->assignee !== null && $card->assignee !== ''): ?>
                                <span class="faint"><?= TemplateRenderer::escape($card->assignee) ?></span>
                            <?php endif; ?>
                        </dd>
                        <dt>Workflow</dt>
                        <dd>
                            <?php if ($flow === null): ?>
                                <span class="faint">agent-loop has no projection for this card</span>
                            <?php else: ?>
                                <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($flow->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($flow->state)) ?></span>
                                <span class="faint"><?= TemplateRenderer::escape(Presentation::label($flow->mode)) ?></span>
                            <?php endif; ?>
                        </dd>
                        <?php if ($runnerSnap !== null && $runnerSnap->observationStatus !== null): ?>
                            <dt>Execution</dt>
                            <dd>
                                <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($runnerSnap->observationStatus)) ?>">
                                    Runner: <?= TemplateRenderer::escape(Presentation::label($runnerSnap->observationStatus)) ?>
                                </span>
                                <?php if ($runnerSnap->hostId !== null): ?>
                                    <span class="faint" style="font-size:10px" title="Runner host"><?= TemplateRenderer::escape($runnerSnap->hostId) ?></span>
                                <?php endif; ?>
                                <?php if ($runnerSnap->observationStageId !== null): ?>
                                    <span class="faint" style="font-size:10px">stage: <?= TemplateRenderer::escape($runnerSnap->observationStageId) ?></span>
                                <?php endif; ?>
                            </dd>
                        <?php endif; ?>
                        <?php if ($flow !== null): ?>
                            <dt>Next</dt>
                            <dd title="<?= TemplateRenderer::escape(Presentation::nextActionKindHint($flow->nextActionKind)) ?>">
                                <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($flow->nextActionKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($flow->nextActionKind)) ?></span>
                            </dd>
                            <?php if ($flow->nextAction !== '' && $flow->nextAction !== 'none'): ?>
                                <dd style="margin-top:2px;width:100%">
                                    <code class="small faint" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;font-size:10px;padding:2px 4px;background:var(--surface-muted);border-radius:3px" title="<?= TemplateRenderer::escape($flow->nextAction) ?>"><?= TemplateRenderer::escape($flow->nextAction) ?></code>
                                </dd>
                            <?php endif; ?>
                        <?php endif; ?>
                    </dl>
                    <?php foreach ($flow->disagreements ?? [] as $disagreement): ?>
                        <p class="card__disagreement">
                            <strong><?= TemplateRenderer::escape($disagreement['owner']) ?>:</strong> <?= TemplateRenderer::escape($disagreement['message']) ?>
                            <a href="/task/<?= TemplateRenderer::escape($card->id) ?>/progress" style="display:block;margin-top:3px;font-size:10.5px;color:inherit;text-decoration:underline">Open Task Cockpit to reconcile →</a>
                        </p>
                    <?php endforeach; ?>
                    <div style="margin-top:8px;padding-top:6px;border-top:1px solid var(--rule);display:flex;justify-content:space-between;align-items:center;gap:4px;flex-wrap:wrap">
                        <div style="display:flex;gap:4px;flex-wrap:wrap">
                            <?php foreach ($card->allowedTransitions as $targetLane): ?>
                                <form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/move" style="margin:0">
                                    <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                                    <input type="hidden" name="target_lane" value="<?= TemplateRenderer::escape($targetLane) ?>">
                                    <input type="hidden" name="return_to" value="/board<?= TemplateRenderer::escape($boardQuery) ?>">
                                    <button class="btn btn--small" type="submit" style="font-size:10px;padding:2px 6px">→ <?= TemplateRenderer::escape($targetLane) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                        <a class="btn btn--small" style="font-size:10px;padding:2px 6px" href="/task/<?= TemplateRenderer::escape($card->id) ?>/progress">Cockpit →</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
