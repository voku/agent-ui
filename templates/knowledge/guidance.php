<?php
use voku\AgentLearning\Catalog\GuidanceProjection;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{guidance: GuidanceProjection} $model */
$guidance = $model['guidance'];
$title = $guidance->id . ' · Guidance · agent-ui';
$nav = 'knowledge';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/knowledge">Knowledge</a><span>/</span><?= TemplateRenderer::escape($guidance->type->value) ?></p>
<div class="page-head"><span class="page-head__id"><?= TemplateRenderer::escape($guidance->id) ?></span><h1><?= TemplateRenderer::escape(ucfirst($guidance->type->value)) ?></h1><p class="lede">Durable guidance projected by agent-learning, with its source lineage and observed usefulness kept visible.</p></div>
<section class="panel<?= $guidance->type->value === 'constraint' ? ' panel--attention' : '' ?>">
    <div class="action__head"><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($guidance->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($guidance->status)) ?></span><span class="provenance provenance--authority">Learning · <?= TemplateRenderer::escape($guidance->type->value) ?></span></div>
    <dl class="kv" style="margin-top:12px"><dt>Source proposal</dt><dd><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($guidance->sourceProposalId) ?>"><?= TemplateRenderer::escape($guidance->sourceProposalId) ?></a></dd><dt>Canonical target</dt><dd><code><?= TemplateRenderer::escape($guidance->canonicalTarget ?? 'not recorded') ?></code></dd></dl>
    <?php if ($guidance->type->value === 'constraint'): ?><p class="note"><strong>Hard constraint.</strong> This is intentionally not displayed as generic memory or advice.</p><?php endif; ?>
</section>
<p class="eyebrow">Content</p><section class="panel"><?php if ($guidance->content === null): ?><p class="empty">No safe content projection is available.</p><?php else: ?><div class="codeblock"><pre><?= TemplateRenderer::escape($guidance->content) ?></pre></div><?php endif; ?></section>
<p class="eyebrow">Scope &amp; source findings</p><div class="split"><section class="panel"><h2>Scope</h2><?php if ($guidance->scope === []): ?><p class="empty">No scope recorded.</p><?php else: ?><div class="stack"><?php foreach ($guidance->scope as $scope): ?><code><?= TemplateRenderer::escape($scope) ?></code><?php endforeach; ?></div><?php endif; ?></section><section class="panel"><h2>Finding lineage</h2><?php if ($guidance->sourceFindingIds === []): ?><p class="empty">No finding lineage recorded.</p><?php else: ?><div class="stack"><?php foreach ($guidance->sourceFindingIds as $id): ?><a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a><?php endforeach; ?></div><?php endif; ?></section></div>
<p class="eyebrow">Usefulness evidence</p><section class="panel"><div class="grid"><div><p class="note">Selected</p><p class="metric"><?= $guidance->usage->selected ?></p></div><div><p class="note">Applied</p><p class="metric"><?= $guidance->usage->applied ?></p></div><div><p class="note">Helpful</p><p class="metric"><?= $guidance->usage->helpful ?></p></div><div><p class="note">Irrelevant</p><p class="metric"><?= $guidance->usage->irrelevant ?></p></div><div><p class="note">Harmful</p><p class="metric"><?= $guidance->usage->harmful ?></p></div><div><p class="note">Violations</p><p class="metric"><?= $guidance->usage->violationDetected ?></p></div></div><dl class="kv" style="margin-top:16px"><dt>Eligible</dt><dd><?= $guidance->usage->eligible ?></dd><dt>Not used</dt><dd><?= $guidance->usage->notUsed ?></dd><dt>Unknown outcome</dt><dd><?= $guidance->usage->unknown ?></dd><dt>Validation success</dt><dd><?= $guidance->usage->validationSuccess ?></dd></dl><?php if ($guidance->usage->taskIds !== []): ?><p class="note">Observed tasks</p><div class="stack"><?php foreach ($guidance->usage->taskIds as $taskId): ?><a class="mono" href="/task/<?= TemplateRenderer::escape($taskId) ?>/learning"><?= TemplateRenderer::escape($taskId) ?></a><?php endforeach; ?></div><?php endif; ?><p class="note">These are Learning-owned outcome observations. They do not turn guidance into workflow authority or auto-retire it.</p></section>
<?php require __DIR__ . '/../layout/footer.php'; ?>
