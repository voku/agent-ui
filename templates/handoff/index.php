<?php
use voku\AgentUi\Feature\Handoff\GuidedHandoffViewModel;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{handoff: GuidedHandoffViewModel} $model */
$handoff = $model['handoff'];
$title = $handoff->taskId . ' · Coding-agent handoff · Agent UI';
require __DIR__ . '/../layout/header.php';
?>
<h1><?= TemplateRenderer::escape($handoff->taskId) ?> · Continue in coding agent</h1>
<p><?= TemplateRenderer::escape($handoff->title) ?></p>
<div class="grid">
<section class="panel"><h2>Execution method</h2><p><strong>Coding-agent session</strong></p><p class="muted">The coding agent performs host-native work. agent-loop remains workflow authority.</p></section>
<section class="panel"><h2>Owner projection</h2><dl><dt>State</dt><dd><?= TemplateRenderer::escape($handoff->state) ?></dd><dt>Next action kind</dt><dd><?= TemplateRenderer::escape($handoff->nextActionKind) ?></dd></dl></section>
</div>
<section class="panel <?= $handoff->nextActionKind === 'decision_required' ? 'attention' : '' ?>"><h2>Canonical next action</h2><pre><?= TemplateRenderer::escape($handoff->nextAction) ?></pre><p class="muted">This is copied verbatim from agent-loop. The handoff builder does not calculate a replacement action.</p></section>
<section class="panel"><h2>Governed start / continue prompt</h2><textarea class="handoff-prompt" readonly rows="18"><?= TemplateRenderer::escape($handoff->prompt) ?></textarea><p class="muted">Copy this text into the coding-agent session. No chat transcript is required.</p></section>
<p><a class="button" href="/task/<?= TemplateRenderer::escape($handoff->taskId) ?>">Back to task</a></p>
<?php require __DIR__ . '/../layout/footer.php'; ?>
