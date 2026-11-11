<?php
use voku\AgentUi\Feature\Board\BoardAction;
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/**
 * @var array{
 *     board: BoardSnapshot,
 *     cards: list<CardSnapshot>,
 *     workflow: array<string, WorkflowSnapshot|null>,
 *     statuses: list<string>,
 *     kind_counts: array<string, int>,
 *     human_decision_count: int,
 *     pending_attention: array<string, \voku\AgentLoop\Execution\AttentionRequest>,
 *     attention_unavailable_ids: list<string>,
 *     disagreement_count: int,
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
$statuses = $model['statuses'];
$kindCounts = $model['kind_counts'];
$humanDecisionCount = $model['human_decision_count'];
$pendingAttention = $model['pending_attention'];
$attentionUnavailableIds = $model['attention_unavailable_ids'];
$disagreementCount = $model['disagreement_count'];
$csrf = $model['csrf_token'];
$filterQuery = $model['filter_query'];
$filterStatus = $model['filter_status'];
$filterPriority = $model['filter_priority'];
$filterWorkflow = $model['filter_workflow'];
$boardQuery = $board->id !== null ? '?board=' . rawurlencode($board->id) : '';
$workflowHref = static fn (string $value): string => '/board?' . http_build_query(array_filter(['board' => $board->id, 'workflow' => $value], static fn (?string $v): bool => $v !== null));

$hasFilter = $filterQuery !== '' || $filterStatus !== '' || $filterPriority !== '' || $filterWorkflow !== '';

/** @var list<CardSnapshot> $decisionCards */
$decisionCards = array_values(array_filter(
    $filteredCards,
    static fn (CardSnapshot $card): bool => ($workflow[$card->id] ?? null)?->nextActionKind === 'decision_required'
        || isset($pendingAttention[$card->id]),
));

$title = 'Board · ' . $board->projectPrefix . ' · agent-ui';
$nav = 'board';
$projectLabel = $board->projectPrefix;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
        <h1><?= TemplateRenderer::escape($board->title ?? 'Board') ?></h1>
        <p class="lede">Each card shows two owners side by side: <strong>Board</strong> is agent-kanban's lane and status,
            <strong>Workflow</strong> is agent-loop's lifecycle state and the kind of step it expects next.
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
        <div style="min-width:150px">
            <label class="field">
                <span>Workflow</span>
                <select name="workflow">
                    <option value="">Any next step</option>
                    <?php foreach ($kindCounts as $kind => $count): ?>
                        <option value="<?= TemplateRenderer::escape((string) $kind) ?>"<?= $filterWorkflow === (string) $kind ? ' selected' : '' ?>><?= $kind === 'decision_required' ? 'Human decision / Attention' : 'Next: ' . TemplateRenderer::escape(Presentation::label((string) $kind)) ?></option>
                    <?php endforeach; ?>
                    <?php if ($humanDecisionCount > 0 && !isset($kindCounts['decision_required'])): ?>
                        <option value="decision_required"<?= $filterWorkflow === 'decision_required' ? ' selected' : '' ?>>Human decision / Attention</option>
                    <?php endif; ?>
                    <?php if ($disagreementCount > 0 || $filterWorkflow === BoardAction::FILTER_DISAGREEMENT): ?>
                        <option value="<?= BoardAction::FILTER_DISAGREEMENT ?>"<?= $filterWorkflow === BoardAction::FILTER_DISAGREEMENT ? ' selected' : '' ?>>Board and workflow disagree</option>
                    <?php endif; ?>
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

<?php if ($kindCounts !== [] || $disagreementCount > 0 || $humanDecisionCount > 0 || $attentionUnavailableIds !== []): ?>
<nav class="board-summary" aria-label="Workflow summary">
    <?php $decisions = $humanDecisionCount; ?>
    <a class="board-summary__item<?= $decisions > 0 ? ' board-summary__item--attention' : '' ?>" href="<?= TemplateRenderer::escape($workflowHref('decision_required')) ?>"<?= $filterWorkflow === 'decision_required' ? ' aria-current="true"' : '' ?>>
        <strong><?= $decisions ?></strong> <?= $attentionUnavailableIds !== [] ? 'confirmed waiting; Attention visibility incomplete' : 'waiting on a human decision' ?>
    </a>
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
            <a href="/task/<?= TemplateRenderer::escape($unavailableId) ?>"><?= TemplateRenderer::escape($unavailableId) ?></a>
        <?php endforeach; ?>
        <?= count($attentionUnavailableIds) > 5 ? 'and ' . (count($attentionUnavailableIds) - 5) . ' more' : '' ?>
    </p>
</section>
<?php endif; ?>

<?php if ($decisionCards !== []): ?>
<section class="board-decision-queue" id="board-decision-queue" aria-labelledby="board-decision-queue-title">
    <header class="board-decision-queue__head">
        <div>
            <h2 id="board-decision-queue-title">Human decisions waiting</h2>
            <p>These are agent-loop's current decisions, regardless of their agent-kanban lane. A pending decision never moves a card by itself.</p>
        </div>
        <span class="pill pill--attention"><?= count($decisionCards) ?> shown</span>
    </header>
    <ul class="board-decision-queue__list">
        <?php foreach ($decisionCards as $card): ?>
            <?php $decision = $workflow[$card->id] ?? null; ?>
            <li class="board-decision-queue__item">
                <div class="board-decision-queue__identity">
                    <div>
                        <span class="board-decision-queue__id"><?= TemplateRenderer::escape($card->id) ?></span>
                        <strong><?= TemplateRenderer::escape($card->title) ?></strong>
                    </div>
                    <a class="btn btn--small" href="/task/<?= TemplateRenderer::escape($card->id) ?>#decision">Inspect decision</a>
                </div>
                <p class="board-decision-queue__owner">
                    <?php if (isset($pendingAttention[$card->id])): ?>
                        <span>agent-loop Attention: <strong><?= TemplateRenderer::escape($pendingAttention[$card->id]->kind->value) ?></strong></span>
                    <?php endif; ?>
                    <span>agent-kanban lane: <strong><?= TemplateRenderer::escape($card->lane) ?></strong></span>
                    <?php if ($decision !== null): ?>
                        <span>agent-loop next: <strong><?= TemplateRenderer::escape(Presentation::label($decision->nextActionKind)) ?></strong></span>
                    <?php else: ?>
                        <span>agent-loop workflow projection: <strong>unavailable</strong></span>
                    <?php endif; ?>
                </p>
                <details class="board-decision-queue__action">
                    <summary>Show owner decision context</summary>
                    <?php if (isset($pendingAttention[$card->id])): ?>
                        <p><?= TemplateRenderer::escape($pendingAttention[$card->id]->message) ?></p>
                    <?php endif; ?>
                    <?php if ($decision !== null): ?>
                        <p><?= TemplateRenderer::escape($decision->nextAction) ?></p>
                    <?php else: ?>
                        <p>Canonical next action unavailable; inspect the live task owner state before acting.</p>
                    <?php endif; ?>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>
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
                $cardClass = 'card';
                if ($flow !== null && $flow->disagreements !== []) {
                    $cardClass .= ' card--blocked';
                } elseif ($flow !== null && $flow->nextActionKind === 'decision_required') {
                    $cardClass .= ' card--attention';
                }
                ?>
                <div class="<?= $cardClass ?>">
                    <a class="card__id" href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a>
                    <a class="card__title" href="/task/<?= TemplateRenderer::escape($card->id) ?>" style="text-decoration:none;color:inherit;font-weight:600"><?= TemplateRenderer::escape($card->title) ?></a>
                    <?php if ($card->summary !== ''): ?>
                        <p class="small faint" style="margin:4px 0 0;line-height:1.3"><?= TemplateRenderer::escape(mb_substr($card->summary, 0, 90)) ?><?= mb_strlen($card->summary) > 90 ? '…' : '' ?></p>
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
                        <?php if ($flow !== null): ?>
                            <dt>Next</dt>
                            <dd title="<?= TemplateRenderer::escape(Presentation::nextActionKindHint($flow->nextActionKind)) ?>">
                                <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($flow->nextActionKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($flow->nextActionKind)) ?></span>
                            </dd>
                        <?php endif; ?>
                    </dl>
                    <?php foreach ($flow->disagreements ?? [] as $disagreement): ?>
                        <p class="card__disagreement"><strong><?= TemplateRenderer::escape($disagreement['owner']) ?>:</strong> <?= TemplateRenderer::escape($disagreement['message']) ?></p>
                    <?php endforeach; ?>
                    <?php if ($card->allowedTransitions !== []): ?>
                        <div style="margin-top:8px;padding-top:6px;border-top:1px solid var(--rule);display:flex;gap:4px;flex-wrap:wrap">
                            <?php foreach ($card->allowedTransitions as $targetLane): ?>
                                <form method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/move" style="margin:0">
                                    <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                                    <input type="hidden" name="target_lane" value="<?= TemplateRenderer::escape($targetLane) ?>">
                                    <input type="hidden" name="return_to" value="/board<?= TemplateRenderer::escape($boardQuery) ?>">
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
