<?php
use voku\AgentUi\Integration\AgentLoop\TaskAuditSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{audit: TaskAuditSnapshot} $model */
$audit = $model['audit'];
$title = $audit->taskId . ' history';
require __DIR__ . '/../layout/header.php';
?>
<h1><?= TemplateRenderer::escape($audit->taskId) ?> · Audit history</h1>
<p class="muted">Newest first. This timeline contains only timestamped facts read from owner records; absence is not filled with inferred events.</p>
<?php if ($audit->timeline === []): ?><section class="panel"><p>No timestamped audit events are currently available.</p></section><?php else: ?>
<?php foreach ($audit->timeline as $entry): ?><section class="panel"><p class="muted"><?= TemplateRenderer::escape($entry->at) ?> · <?= TemplateRenderer::escape($entry->kind) ?></p><h2><?= TemplateRenderer::escape($entry->title) ?></h2><p><?= TemplateRenderer::escape($entry->detail) ?></p></section><?php endforeach; ?>
<?php endif; ?>
<p><a class="button" href="/task/<?= TemplateRenderer::escape($audit->taskId) ?>/evidence">Evidence & audit</a> <a class="button" href="/task/<?= TemplateRenderer::escape($audit->taskId) ?>">Back to task</a></p>
<?php require __DIR__ . '/../layout/footer.php'; ?>
