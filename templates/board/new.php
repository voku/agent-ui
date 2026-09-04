<?php
use voku\AgentUi\Integration\AgentKanban\BoardSnapshot;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{board: BoardSnapshot, suggested_id: string, lanes: list<string>, csrf_token: string} $model */
$board = $model['board'];
$suggestedId = $model['suggested_id'];
$lanes = $model['lanes'];
$csrf = $model['csrf_token'];

$title = 'New card · ' . $board->projectPrefix . ' · agent-ui';
$nav = 'board';
$projectLabel = $board->projectPrefix;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span>New card</p>
<div class="page-head">
    <h1>Create Kanban Card</h1>
    <p class="lede">Add a new TODO card to the governed board via agent-kanban.</p>
</div>

<?php if (count($board->boards) > 1): ?>
<nav class="board-switcher" aria-label="Target board">
    <?php foreach ($board->boards as $b): ?>
        <a href="/board/new?board=<?= rawurlencode($b->id) ?>"<?= $b->active ? ' aria-current="page"' : '' ?>>
            <span><?= TemplateRenderer::escape($b->title) ?></span>
            <span class="pill pill--muted" style="font-size:11px"><?= TemplateRenderer::escape($b->projectPrefix) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<section class="panel">
    <form class="form" method="post" action="/board/new">
        <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
        <?php if ($board->id !== null): ?>
            <input type="hidden" name="board_id" value="<?= TemplateRenderer::escape($board->id) ?>">
        <?php endif; ?>

        <div class="grid">
            <div>
                <label class="field">
                    <span>Card ID</span>
                    <input required maxlength="50" name="card_id" value="<?= TemplateRenderer::escape($suggestedId) ?>" placeholder="e.g. <?= TemplateRenderer::escape($board->projectPrefix) ?>-1">
                </label>
                <p class="note">Identifier format: PREFIX-NUMBER. Pre-filled with the next available number.</p>
            </div>
            <div>
                <label class="field">
                    <span>Lane</span>
                    <select required name="lane">
                        <?php foreach ($lanes as $lane): ?>
                            <option value="<?= TemplateRenderer::escape($lane) ?>"<?= $lane === 'BACKLOG' || $lane === ($lanes[0] ?? '') ? ' selected' : '' ?>>
                                <?= TemplateRenderer::escape($lane) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="note">Initial lane on the board.</p>
            </div>
        </div>

        <div class="form__row" style="margin-top:14px">
            <label class="field field--wide">
                <span>Title *</span>
                <input required maxlength="500" name="title" autocomplete="off" placeholder="Short, descriptive summary of the work">
            </label>
        </div>

        <div class="grid" style="margin-top:14px">
            <div>
                <label class="field">
                    <span>Status</span>
                    <input maxlength="100" name="status" placeholder="e.g. Open, Selected, In Progress">
                </label>
            </div>
            <div>
                <label class="field">
                    <span>Priority</span>
                    <select name="priority">
                        <option value="">None</option>
                        <option value="1">P1 - Highest</option>
                        <option value="2">P2 - High</option>
                        <option value="3">P3 - Medium</option>
                        <option value="4">P4 - Low</option>
                        <option value="5">P5 - Lowest</option>
                    </select>
                </label>
            </div>
            <div>
                <label class="field">
                    <span>Assignee</span>
                    <input maxlength="100" name="assignee" placeholder="username or actor">
                </label>
            </div>
        </div>

        <div class="form__row" style="margin-top:14px">
            <label class="field field--wide">
                <span>Summary</span>
                <textarea rows="2" maxlength="2000" name="summary" placeholder="Brief context or rationale for this task"></textarea>
            </label>
        </div>

        <div class="form__row" style="margin-top:14px">
            <label class="field field--wide">
                <span>Task Brief</span>
                <textarea rows="5" maxlength="10000" name="task_brief" placeholder="Detailed requirements, problem statement, or scope description (Markdown format)"></textarea>
            </label>
        </div>

        <div class="grid" style="margin-top:14px">
            <div>
                <label class="field">
                    <span>Next action</span>
                    <input maxlength="500" name="next_action" placeholder="e.g. vendor/bin/agent-loop enter ...">
                </label>
            </div>
            <div>
                <label class="field">
                    <span>Validation command</span>
                    <input maxlength="500" name="validation" placeholder="e.g. composer test">
                </label>
            </div>
        </div>

        <div class="btn-row" style="margin-top:20px">
            <button class="btn btn--primary" type="submit">Create Card</button>
            <a class="btn" href="/board">Cancel</a>
        </div>
    </form>
</section>
<?php require __DIR__ . '/../layout/footer.php'; ?>
