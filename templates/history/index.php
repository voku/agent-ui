<?php
use voku\AgentUi\Feature\History\TaskActivity;
use voku\AgentUi\Integration\AgentLoop\TaskAuditSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{audit: TaskAuditSnapshot, activity: TaskActivity} $model */
$audit = $model['audit'];
$activity = $model['activity'];
$taskContext = $model['task_context'];
$title = $audit->taskId . ' · History · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($audit->taskId) ?>"><?= TemplateRenderer::escape($audit->taskId) ?></a><span>/</span>History</p>
<?php $taskNavCurrent = '/history'; require __DIR__ . '/../layout/task-context.php'; ?>
<div class="page-head">
    <h1>Audit history</h1>
    <p class="lede">Newest first. Every entry is a timestamped fact read from an owner record — absence is left
        as absence rather than filled in with an inferred event.</p>
</div>

<?php if ($activity->events === []): ?>
    <section class="panel"><p class="empty">No owner has published a timestamped fact for this task yet.</p></section>
<?php else: ?>
    <section class="panel">
        <ol class="timeline">
            <?php foreach ($activity->events as $event): ?>
                <li>
                    <span class="timeline__when"><?= TemplateRenderer::escape($event->at) ?></span>
                    <span class="pill pill--neutral" style="margin-left:8px"><?= TemplateRenderer::escape($event->kind) ?></span>
                    <p class="timeline__title"><?= TemplateRenderer::escape($event->title) ?></p>
                    <p class="timeline__detail"><?= TemplateRenderer::escape($event->detail) ?></p>
                    <p class="provenance provenance--authority"><?= TemplateRenderer::escape($event->owner) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<?php if ($activity->untimed !== []): ?>
    <p class="eyebrow">Known, but not placed in time</p>
    <section class="panel">
        <p class="note" style="margin-top:0">These facts belong to this task and their owners publish no timestamp for
            them. Putting them on the timeline would mean the UI deciding when they happened, so they are listed here
            with the field that is missing instead.</p>
        <div class="stack">
            <?php foreach ($activity->untimed as $fact): ?>
                <article>
                    <p class="provenance provenance--authority"><?= TemplateRenderer::escape($fact->owner) ?> · <?= TemplateRenderer::escape($fact->kind) ?></p>
                    <p class="timeline__title" style="margin-top:4px"><?= TemplateRenderer::escape($fact->title) ?></p>
                    <p class="timeline__detail"><?= TemplateRenderer::escape($fact->detail) ?></p>
                    <p class="note"><?= TemplateRenderer::escape($fact->missing) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
