<?php
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{workflow: WorkflowSnapshot} $model */
$workflow=$model['workflow']; $title=$workflow->taskId . ' evidence'; require __DIR__ . '/../layout/header.php';
?>
<h1><?= TemplateRenderer::escape($workflow->taskId) ?> evidence</h1><p class="muted">References are owner-projected pointers. Generated evidence is not approval or workflow authority.</p>
<?php foreach ($workflow->references as $name=>$reference): ?><section class="panel"><h2><?= TemplateRenderer::escape((string)$name) ?></h2><pre><?= TemplateRenderer::escape((string) json_encode($reference, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)) ?></pre></section><?php endforeach; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
