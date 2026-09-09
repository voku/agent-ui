<?php
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentUi\Integration\AgentLearning\FindingPromotionSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{finding: FindingProjection, promotion: ?FindingPromotionSnapshot} $model */
$finding = $model['finding'];
$promotion = $model['promotion'];
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
<?php if ($promotion !== null): ?>
    <?php // agent-learning decided this; the UI renders its verdict and its
          // blocker vocabulary, and offers no way to promote from here. ?>
    <p class="eyebrow" style="margin-top:20px">Will this be seen again?</p>
    <section class="panel <?= $promotion->promotable ? 'panel--accent' : 'panel--attention' ?>" aria-label="Promotion readiness">
        <div class="action__head">
            <strong>
                <?php if ($promotion->promotable): ?>
                    This Finding can become a LearningNote
                <?php else: ?>
                    Nothing will ever surface this Finding
                <?php endif; ?>
            </strong>
            <span class="pill pill--neutral">agent-learning</span>
        </div>
        <?php if ($promotion->promotable): ?>
            <p class="small" style="margin-top:10px">
                It carries everything <code>LearningNoteService::prepare()</code> requires
                <?php if ($promotion->patternKey !== null): ?>
                    and would be filed under <code><?= TemplateRenderer::escape($promotion->patternKey) ?></code>
                <?php endif; ?>.
                Promotion is still a human decision and is not offered here.
            </p>
        <?php else: ?>
            <p class="small" style="margin-top:10px">
                Only a LearningNote can be returned as a precedent to a later task. Until this Finding becomes one it
                is recorded and never read back, however good the lesson is.
            </p>
            <p class="small faint" style="margin:8px 0 0">agent-learning reports <?= count($promotion->blockers) ?> reason(s):</p>
            <div class="stack" style="margin-top:6px">
                <?php foreach ($promotion->blockers as $blocker): ?>
                    <p class="small" style="margin:0">
                        &bull; <?= TemplateRenderer::escape(FindingPromotionSnapshot::blockerLabel($blocker)) ?>
                        <span class="faint mono">(<?= TemplateRenderer::escape($blocker) ?>)</span>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<p class="eyebrow">Scope</p><section class="panel"><?php if ($finding->scope === []): ?><p class="empty">No scope recorded.</p><?php else: ?><div class="stack"><?php foreach ($finding->scope as $scope): ?><code><?= TemplateRenderer::escape($scope) ?></code><?php endforeach; ?></div><?php endif; ?></section>
<p class="eyebrow">Evidence</p><section class="panel"><?php if ($finding->evidence === []): ?><p class="empty">No evidence references recorded.</p><?php else: ?><div class="codeblock"><pre><?= TemplateRenderer::escape((string) json_encode($finding->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre></div><?php endif; ?><p class="note">Rendered from Learning's validated projection; agent-ui does not reinterpret evidence as acceptance.</p></section>
<p class="eyebrow">Derived proposals</p><section class="panel"><?php if ($finding->proposalIds === []): ?><p class="empty">No proposal is linked to this Finding.</p><?php else: ?><div class="stack"><?php foreach ($finding->proposalIds as $id): ?><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/../layout/footer.php'; ?>
