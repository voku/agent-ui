<?php
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{overview: LearningOverview, recent_findings: list<FindingProjection>, recent_proposals: list<ProposalProjection>} $model */
$overview = $model['overview'];
$findings = $model['recent_findings'];
$proposals = $model['recent_proposals'];
$title = 'Knowledge · agent-ui';
$nav = 'knowledge';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <span class="page-head__id">Learning truth</span>
    <h1>Knowledge</h1>
    <p class="lede">What coding sessions taught this repository, what still needs judgment, what became durable, and what history was rejected or superseded. Every item comes from agent-learning's typed catalog.</p>
</div>

<p class="eyebrow">Needs attention</p>
<div class="grid">
    <section class="panel<?= $overview->findingAttentionIds !== [] ? ' panel--attention' : '' ?>">
        <h2>Findings</h2>
        <p class="metric"><?= count($overview->findingAttentionIds) ?></p>
        <p class="note">Candidate or validated findings that Learning currently classifies as attention.</p>
        <?php foreach ($overview->findingAttentionIds as $id): ?><p style="margin:6px 0"><a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a></p><?php endforeach; ?>
    </section>
    <section class="panel<?= $overview->proposalAttentionIds !== [] ? ' panel--attention' : '' ?>">
        <h2>Proposals</h2>
        <p class="metric"><?= count($overview->proposalAttentionIds) ?></p>
        <p class="note">Candidate proposals awaiting an owner decision.</p>
        <?php foreach ($overview->proposalAttentionIds as $id): ?><p style="margin:6px 0"><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a></p><?php endforeach; ?>
    </section>
</div>

<p class="eyebrow">Durable guidance</p>
<section class="panel">
    <div class="grid">
        <?php foreach ($overview->guidanceCounts as $type => $count): ?>
            <div><p class="provenance provenance--authority">Learning · <?= TemplateRenderer::escape((string) $type) ?></p><p class="metric"><?= (int) $count ?></p></div>
        <?php endforeach; ?>
    </div>
    <?php if ($overview->recentDurableGuidanceIds !== []): ?>
        <h2 style="margin-top:20px">Recent durable changes</h2>
        <div class="stack"><?php foreach ($overview->recentDurableGuidanceIds as $id): ?><a class="mono" href="/knowledge/guidance/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
</section>

<p class="eyebrow">Recent Learning history</p>
<div class="split">
    <section class="panel">
        <h2>Findings</h2>
        <?php if ($findings === []): ?><p class="empty">No findings recorded.</p><?php else: ?>
            <div class="stack">
                <?php foreach ($findings as $finding): ?>
                    <article>
                        <div class="action__head"><a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($finding->id) ?>"><?= TemplateRenderer::escape($finding->id) ?></a><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($finding->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($finding->status)) ?></span></div>
                        <p class="small" style="margin:6px 0 0"><?= TemplateRenderer::escape($finding->observation) ?></p>
                        <p class="note" style="margin-top:3px">task <?= TemplateRenderer::escape($finding->taskId) ?> · <?= TemplateRenderer::escape($finding->createdAt) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>Proposals</h2>
        <?php if ($proposals === []): ?><p class="empty">No proposals recorded.</p><?php else: ?>
            <div class="stack">
                <?php foreach ($proposals as $proposal): ?>
                    <article>
                        <div class="action__head"><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($proposal->id) ?>"><?= TemplateRenderer::escape($proposal->id) ?></a><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($proposal->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($proposal->status)) ?></span></div>
                        <p class="small" style="margin:6px 0 0"><?= TemplateRenderer::escape($proposal->reason) ?></p>
                        <p class="note" style="margin-top:3px"><?= TemplateRenderer::escape($proposal->action) ?> · <?= TemplateRenderer::escape($proposal->createdAt) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<details class="raw">
    <summary>Status counts from Learning</summary>
    <div class="split">
        <section class="panel"><h2>Finding states</h2><dl class="kv"><?php foreach ($overview->findingCounts as $state => $count): ?><dt><?= TemplateRenderer::escape((string) $state) ?></dt><dd><?= (int) $count ?></dd><?php endforeach; ?></dl></section>
        <section class="panel"><h2>Proposal states</h2><dl class="kv"><?php foreach ($overview->proposalCounts as $state => $count): ?><dt><?= TemplateRenderer::escape((string) $state) ?></dt><dd><?= (int) $count ?></dd><?php endforeach; ?></dl></section>
    </div>
</details>
<?php require __DIR__ . '/../layout/footer.php'; ?>
