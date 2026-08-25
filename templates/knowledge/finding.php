<?php
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{finding: FindingProjection} $model */
$finding = $model['finding'];
$title = $finding->id . ' · Finding · agent-ui';
$nav = 'knowledge';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/knowledge">Knowledge</a><span>/</span>Finding</p>
<div class="page-head"><span class="page-head__id"><?= TemplateRenderer::escape($finding->id) ?></span><h1>Finding</h1><p class="lede"><?= TemplateRenderer::escape($finding->observation) ?></p></div>
<section class="panel">
    <div class="action__head"><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($finding->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($finding->status)) ?></span><span class="small faint"><?= TemplateRenderer::escape($finding->createdAt) ?></span></div>
    <dl class="kv" style="margin-top:12px"><dt>Task</dt><dd><a href="/task/<?= TemplateRenderer::escape($finding->taskId) ?>/learning"><?= TemplateRenderer::escape($finding->taskId) ?></a></dd><dt>Session</dt><dd class="mono"><?= TemplateRenderer::escape($finding->session) ?></dd><?php if ($finding->validatedConclusion !== null): ?><dt>Validated conclusion</dt><dd><?= TemplateRenderer::escape($finding->validatedConclusion) ?></dd><?php endif; ?></dl>
</section>
<p class="eyebrow">Scope</p><section class="panel"><?php if ($finding->scope === []): ?><p class="empty">No scope recorded.</p><?php else: ?><div class="stack"><?php foreach ($finding->scope as $scope): ?><code><?= TemplateRenderer::escape($scope) ?></code><?php endforeach; ?></div><?php endif; ?></section>
<p class="eyebrow">Evidence</p><section class="panel"><?php if ($finding->evidence === []): ?><p class="empty">No evidence references recorded.</p><?php else: ?><div class="codeblock"><pre><?= TemplateRenderer::escape((string) json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre></div><?php endif; ?><p class="note">Rendered from Learning's validated projection; agent-ui does not reinterpret evidence as acceptance.</p></section>
<p class="eyebrow">Derived proposals</p><section class="panel"><?php if ($finding->proposalIds === []): ?><p class="empty">No proposal is linked to this Finding.</p><?php else: ?><div class="stack"><?php foreach ($finding->proposalIds as $id): ?><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/../layout/footer.php'; ?>
