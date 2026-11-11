<?php
use voku\AgentUi\Feature\PromptWorkbench\PromptWorkbenchViewModel;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{workbench: PromptWorkbenchViewModel} $model */
$workbench = $model['workbench'];
$taskContext = $model['task_context'] ?? null;
$title = $workbench->taskAware && $workbench->taskId !== null
    ? $workbench->taskId . ' · Prompt Workbench · agent-ui'
    : 'Prompt Workbench · agent-ui';
$nav = 'prompts';
$projectLabel = 'deterministic prompt workbench';
require __DIR__ . '/../layout/header.php';
?>
<?php if ($workbench->taskAware && $workbench->taskId !== null): ?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($workbench->taskId) ?>"><?= TemplateRenderer::escape($workbench->taskId) ?></a><span>/</span>Prompts</p>
<?php endif; ?>
<?php if ($taskContext !== null): ?>
<?php $taskNavCurrent = '/prompts'; require __DIR__ . '/../layout/task-context.php'; ?>
<?php endif; ?>
<div class="page-head">
    <h1>Prompt Workbench</h1>
    <p class="lede">Choose what you are trying to do, then compose a workflow-owned envelope with the explicitly selected Recall recipe. The UI owns presentation and deterministic composition, not workflow authority or recipe semantics.</p>
</div>

<?php if ($workbench->context !== null): ?>
<p class="eyebrow">Current Recall context</p>
<section class="panel">
    <dl class="facts">
        <div><dt>Status</dt><dd><?= TemplateRenderer::escape($workbench->context->status) ?></dd></div>
        <?php if ($workbench->context->explanation !== null): ?>
            <div><dt>Compilation</dt><dd><?= TemplateRenderer::escape($workbench->context->explanation->compilationId ?? 'unknown') ?></dd></div>
            <div><dt>Bundle</dt><dd class="mono"><?= TemplateRenderer::escape($workbench->context->explanation->bundleSha256) ?></dd></div>
            <div><dt>Selected guidance</dt><dd><?= $workbench->context->selectedGuidanceCount() ?></dd></div>
            <div><dt>Excluded guidance</dt><dd><?= $workbench->context->excludedGuidanceCount() ?></dd></div>
        <?php endif; ?>
    </dl>
    <?php if ($workbench->context->problem !== null): ?><p class="note"><?= TemplateRenderer::escape($workbench->context->problem) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/_form.php'; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
