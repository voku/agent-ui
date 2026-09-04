<?php
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, lanes: list<string>, csrf_token: string} $model */
$card = $model['card'];
$lanes = $model['lanes'];
$csrf = $model['csrf_token'];

$title = 'Edit ' . $card->id . ' · ' . $card->title . ' · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs">
    <a href="/board">Board</a>
    <span>/</span>
    <a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a>
    <span>/</span>
    Edit
</p>

<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($card->id) ?></span>
    <h1>Edit Card: <?= TemplateRenderer::escape($card->title) ?></h1>
    <p class="lede">Modify TODO fields and lane placement governed by agent-kanban.</p>
</div>

<section class="panel">
    <form class="form" method="post" action="/task/<?= TemplateRenderer::escape($card->id) ?>/edit">
        <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
        <input type="hidden" name="expected_revision" value="<?= TemplateRenderer::escape($card->revision) ?>">

        <div class="form__row">
            <label class="field field--wide">
                <span>Title *</span>
                <input required maxlength="500" name="title" value="<?= TemplateRenderer::escape($card->title) ?>">
            </label>
        </div>

        <div class="grid" style="margin-top:14px">
            <div>
                <label class="field">
                    <span>Lane</span>
                    <select name="lane">
                        <?php foreach ($lanes as $lane): ?>
                            <option value="<?= TemplateRenderer::escape($lane) ?>"<?= $lane === $card->lane ? ' selected' : '' ?>>
                                <?= TemplateRenderer::escape($lane) ?><?= $lane === $card->lane ? ' (current)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="note">Moving lanes enforces configured transition rules.</p>
            </div>
            <div>
                <label class="field">
                    <span>Status</span>
                    <input maxlength="100" name="status" value="<?= TemplateRenderer::escape($card->status) ?>" placeholder="e.g. Open, Selected, In Progress">
                </label>
            </div>
            <div>
                <label class="field">
                    <span>Priority</span>
                    <select name="priority">
                        <option value="">None</option>
                        <option value="1"<?= $card->priority === 1 ? ' selected' : '' ?>>P1 - Highest</option>
                        <option value="2"<?= $card->priority === 2 ? ' selected' : '' ?>>P2 - High</option>
                        <option value="3"<?= $card->priority === 3 ? ' selected' : '' ?>>P3 - Medium</option>
                        <option value="4"<?= $card->priority === 4 ? ' selected' : '' ?>>P4 - Low</option>
                        <option value="5"<?= $card->priority === 5 ? ' selected' : '' ?>>P5 - Lowest</option>
                    </select>
                </label>
            </div>
            <div>
                <label class="field">
                    <span>Assignee</span>
                    <input maxlength="100" name="assignee" value="<?= TemplateRenderer::escape($card->assignee ?? '') ?>" placeholder="username or actor">
                </label>
            </div>
        </div>

        <div class="form__row" style="margin-top:14px">
            <label class="field field--wide">
                <span>Summary</span>
                <textarea rows="2" maxlength="2000" name="summary" placeholder="Brief context or rationale for this task"><?= TemplateRenderer::escape($card->summary) ?></textarea>
            </label>
        </div>

        <div class="form__row" style="margin-top:14px">
            <label class="field field--wide">
                <span>Task Brief</span>
                <textarea rows="6" maxlength="10000" name="task_brief" placeholder="Detailed requirements, problem statement, or scope description (Markdown format)"><?= TemplateRenderer::escape($card->taskBrief) ?></textarea>
            </label>
        </div>

        <div class="grid" style="margin-top:14px">
            <div>
                <label class="field">
                    <span>Next action</span>
                    <input maxlength="500" name="next_action" value="<?= TemplateRenderer::escape($card->nextAction) ?>" placeholder="e.g. vendor/bin/agent-loop enter ...">
                </label>
            </div>
            <div>
                <label class="field">
                    <span>Validation command</span>
                    <input maxlength="500" name="validation" value="<?= TemplateRenderer::escape($card->validation) ?>" placeholder="e.g. composer test">
                </label>
            </div>
        </div>

        <div class="btn-row" style="margin-top:20px">
            <button class="btn btn--primary" type="submit">Save Changes</button>
            <a class="btn" href="/task/<?= TemplateRenderer::escape($card->id) ?>">Cancel</a>
        </div>
    </form>
</section>

<?php $taskNavId = $card->id; $taskNavCurrent = '/edit'; require __DIR__ . '/../layout/task-nav.php'; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
