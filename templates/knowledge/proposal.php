<?php
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{proposal: ProposalProjection} $model */
$proposal = $model['proposal'];
$title = $proposal->id . ' · Proposal · agent-ui';
$nav = 'knowledge';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/knowledge">Knowledge</a><span>/</span>Proposal</p>
<div class="page-head"><span class="page-head__id"><?= TemplateRenderer::escape($proposal->id) ?></span><h1>Proposal</h1><p class="lede"><?= TemplateRenderer::escape($proposal->reason) ?></p></div>
<section class="panel">
    <div class="action__head"><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($proposal->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($proposal->status)) ?></span><span class="small faint"><?= TemplateRenderer::escape($proposal->createdAt) ?></span></div>
    <dl class="kv" style="margin-top:12px"><dt>Action</dt><dd><?= TemplateRenderer::escape($proposal->action) ?></dd><dt>Target type</dt><dd><?= TemplateRenderer::escape($proposal->targetType ?? 'not recorded') ?></dd><dt>Target</dt><dd><code><?= TemplateRenderer::escape($proposal->target ?? 'not recorded') ?></code></dd><dt>Proposed by</dt><dd><?= TemplateRenderer::escape($proposal->proposedBy) ?></dd><dt>Approved by</dt><dd><?= TemplateRenderer::escape($proposal->approvedBy ?? 'not approved') ?></dd><dt>Approved at</dt><dd><?= TemplateRenderer::escape($proposal->approvedAt ?? 'not approved') ?></dd></dl>
</section>
<?php if ($proposal->proposedChange !== null): ?><p class="eyebrow">Proposed durable change</p><section class="panel"><div class="codeblock"><pre><?= TemplateRenderer::escape($proposal->proposedChange) ?></pre></div><?php if ($proposal->boundary !== null): ?><p class="note">Boundary: <?= TemplateRenderer::escape($proposal->boundary) ?></p><?php endif; ?></section><?php endif; ?>
<p class="eyebrow">Source lineage</p><div class="split"><section class="panel"><h2>Findings</h2><?php if ($proposal->sourceFindingIds === []): ?><p class="empty">No source findings.</p><?php else: ?><div class="stack"><?php foreach ($proposal->sourceFindingIds as $id): ?><a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a><?php endforeach; ?></div><?php endif; ?></section><section class="panel"><h2>Tasks</h2><?php if ($proposal->sourceTaskIds === []): ?><p class="empty">No task lineage recorded.</p><?php else: ?><div class="stack"><?php foreach ($proposal->sourceTaskIds as $id): ?><a class="mono" href="/task/<?= TemplateRenderer::escape($id) ?>/learning"><?= TemplateRenderer::escape($id) ?></a><?php endforeach; ?></div><?php endif; ?></section></div>
<p class="eyebrow">Scope &amp; validation</p><div class="split"><section class="panel"><h2>Scope</h2><?php if ($proposal->scope === []): ?><p class="empty">No scope recorded.</p><?php else: ?><div class="stack"><?php foreach ($proposal->scope as $scope): ?><code><?= TemplateRenderer::escape($scope) ?></code><?php endforeach; ?></div><?php endif; ?></section><section class="panel"><h2>Validation</h2><?php if ($proposal->validation === []): ?><p class="empty">No validation commands recorded.</p><?php else: ?><div class="stack"><?php foreach ($proposal->validation as $command): ?><code><?= TemplateRenderer::escape($command) ?></code><?php endforeach; ?></div><?php endif; ?></section></div>
<?php if ($proposal->supersedesProposalIds !== [] || $proposal->conflictsWithProposalIds !== [] || $proposal->correctsProposalId !== null): ?><p class="eyebrow">Historical relations</p><section class="panel"><dl class="kv"><?php if ($proposal->correctsProposalId !== null): ?><dt>Corrects</dt><dd><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($proposal->correctsProposalId) ?>"><?= TemplateRenderer::escape($proposal->correctsProposalId) ?></a></dd><?php endif; ?><dt>Supersedes</dt><dd><?= TemplateRenderer::escape(implode(', ', $proposal->supersedesProposalIds) ?: 'none') ?></dd><dt>Conflicts with</dt><dd><?= TemplateRenderer::escape(implode(', ', $proposal->conflictsWithProposalIds) ?: 'none') ?></dd></dl></section><?php endif; ?>
<?php if ($proposal->targetType !== null): ?><p class="btn-row"><a class="btn btn--primary" href="/knowledge/guidance/<?= TemplateRenderer::escape($proposal->id) ?>">View resulting guidance →</a></p><?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
