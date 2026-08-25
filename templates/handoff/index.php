<?php
use voku\AgentUi\Feature\Handoff\GuidedHandoffViewModel;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{handoff: GuidedHandoffViewModel} $model */
$handoff = $model['handoff'];
$title = $handoff->taskId . ' · Coding-agent handoff · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($handoff->taskId) ?>"><?= TemplateRenderer::escape($handoff->taskId) ?></a><span>/</span>Handoff</p>
<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($handoff->taskId) ?></span>
    <h1>Continue in a coding agent</h1>
    <p class="lede"><?= TemplateRenderer::escape($handoff->title) ?></p>
</div>

<p class="eyebrow">Governed prompt</p>
<section class="panel action">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($handoff->state)) ?>"><?= TemplateRenderer::escape(Presentation::label($handoff->state)) ?></span>
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($handoff->nextActionKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($handoff->nextActionKind)) ?></span>
    </div>
    <p class="action__hint">Paste this into your coding-agent session. It hands over a starting point and a dated
        snapshot — not a copy of the workflow. The agent is told to ask agent-loop what is next, because agent-loop
        is the only thing that knows.</p>
    <div class="codeblock">
        <pre id="handoff-prompt"><?= TemplateRenderer::escape($handoff->prompt) ?></pre>
        <button type="button" class="copy" data-copy-target="handoff-prompt">Copy prompt</button>
    </div>
    <p class="note">No chat transcript is required. The prompt is compiled from owner state.</p>
</section>

<p class="eyebrow">Canonical next action</p>
<section class="panel<?= $handoff->nextActionKind === 'decision_required' ? ' panel--attention' : '' ?>">
    <p class="action__hint" style="margin-top:0"><?= TemplateRenderer::escape(Presentation::nextActionKindHint($handoff->nextActionKind)) ?></p>
    <div class="codeblock">
        <pre id="handoff-next"><?= TemplateRenderer::escape($handoff->nextAction) ?></pre>
        <button type="button" class="copy" data-copy-target="handoff-next">Copy</button>
    </div>
    <p class="note">Copied verbatim from agent-loop. The handoff builder does not calculate a replacement action.</p>
</section>

<div class="btn-row">
    <a class="btn" href="/task/<?= TemplateRenderer::escape($handoff->taskId) ?>">← Back to task</a>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
