<?php
use voku\AgentUi\Integration\AgentLoop\TaskAuditSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{audit: TaskAuditSnapshot} $model */
$audit = $model['audit'];
$title = $audit->taskId . ' · History · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($audit->taskId) ?>"><?= TemplateRenderer::escape($audit->taskId) ?></a><span>/</span>History</p>
<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($audit->taskId) ?></span>
    <h1>Audit history</h1>
    <p class="lede">Newest first. Every entry is a timestamped fact read from an owner record — absence is left
        as absence rather than filled in with an inferred event.</p>
</div>

<?php if ($audit->timeline === []): ?>
    <section class="panel"><p class="empty">No timestamped audit events are available for this task yet.</p></section>
<?php else: ?>
    <section class="panel">
        <ol class="timeline">
            <?php foreach ($audit->timeline as $entry): ?>
                <li>
                    <span class="timeline__when"><?= TemplateRenderer::escape($entry->at) ?></span>
                    <span class="pill pill--neutral" style="margin-left:8px"><?= TemplateRenderer::escape($entry->kind) ?></span>
                    <p class="timeline__title"><?= TemplateRenderer::escape($entry->title) ?></p>
                    <p class="timeline__detail"><?= TemplateRenderer::escape($entry->detail) ?></p>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<div class="btn-row">
    <a class="btn" href="/task/<?= TemplateRenderer::escape($audit->taskId) ?>/evidence">Evidence &amp; audit</a>
    <a class="btn" href="/task/<?= TemplateRenderer::escape($audit->taskId) ?>">← Back to task</a>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
