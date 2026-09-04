<?php
use voku\AgentMap\Context\EditContextPlan;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{target: string, plan: EditContextPlan} $model */
$target = $model['target'];
$plan = $model['plan'];

$title = 'Edit Context · ' . $target . ' · agent-ui';
$nav = 'map';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/map">Code Map</a><span>/</span><a href="/map/symbol?id=<?= rawurlencode($plan->resolvedTarget->id) ?>"><?= TemplateRenderer::escape($target) ?></a><span>/</span>Edit Context</p>

<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
        <div style="display:flex;align-items:center;gap:8px">
            <span class="pill pill--ok">Edit Context Plan</span>
            <span class="page-head__id"><?= TemplateRenderer::escape($plan->resolvedTarget->id) ?></span>
        </div>
        <h1 style="margin-top:4px"><?= TemplateRenderer::escape($plan->resolvedTarget->owner->fqn) ?>::<?= TemplateRenderer::escape($plan->resolvedTarget->method->name) ?></h1>
        <p class="lede">Exact source slices, contracts, and dependencies bounded by agent-map policy (<?= count($plan->slices) ?> slice(s), <?= (int) $plan->sourceBytes ?> bytes).</p>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn" href="/map/symbol?id=<?= rawurlencode($plan->resolvedTarget->id) ?>">Inspect Symbol</a>
        <a class="btn btn--primary" href="/board/new?title=<?= rawurlencode('Refactor ' . $plan->resolvedTarget->method->name) ?>&summary=<?= rawurlencode('Edit context generated for ' . $plan->resolvedTarget->owner->fqn . '::' . $plan->resolvedTarget->method->name) ?>&brief=<?= rawurlencode("Target: " . $plan->resolvedTarget->owner->fqn . "::" . $plan->resolvedTarget->method->name . "\nFile: " . $plan->resolvedTarget->file->path . ":" . $plan->resolvedTarget->method->lineStart . "-" . $plan->resolvedTarget->method->lineEnd) ?>">+ Create Task</a>
    </div>
</div>

<p class="eyebrow">Target Summary</p>
<section class="panel panel--accent">
    <dl class="kv">
        <dt>Requested</dt><dd><code><?= TemplateRenderer::escape($plan->requestedTarget) ?></code></dd>
        <dt>File</dt><dd><code><?= TemplateRenderer::escape($plan->resolvedTarget->file->path) ?>:<?= (int) $plan->resolvedTarget->method->lineStart ?>-<?= (int) $plan->resolvedTarget->method->lineEnd ?></code></dd>
        <dt>Return type</dt><dd><?= TemplateRenderer::escape($plan->resolvedTarget->method->displayReturnType() ?? 'mixed/untyped') ?></dd>
        <dt>Policy limits</dt><dd>max <?= (int) $plan->policy->maximumFiles ?> files, max <?= (int) $plan->policy->maximumSourceBytes ?> bytes</dd>
        <dt>Map digest</dt><dd><span class="mono small"><?= TemplateRenderer::escape(substr($plan->mapDigest, 0, 16)) ?>…</span></dd>
    </dl>
</section>

<?php if ($plan->blindSpots !== []): ?>
    <p class="eyebrow" style="margin-top:20px">Blind Spots &amp; Warnings</p>
    <section class="panel panel--attention stack">
        <?php foreach ($plan->blindSpots as $spot): ?>
            <p style="margin:0"><strong>[<?= TemplateRenderer::escape($spot->kind) ?>]</strong> <?= TemplateRenderer::escape($spot->description) ?></p>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<p class="eyebrow" style="margin-top:24px">Source Slices (<?= count($plan->slices) ?>)</p>
<div class="stack">
    <?php foreach ($plan->slices as $slice): ?>
        <article class="panel">
            <div class="action__head">
                <div>
                    <?php foreach ($slice->roles as $role): ?>
                        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($role)) ?>"><?= TemplateRenderer::escape($role) ?></span>
                    <?php endforeach; ?>
                    <strong style="margin-left:8px"><code><?= TemplateRenderer::escape($slice->path) ?>:<?= (int) $slice->lineStart ?>-<?= (int) $slice->lineEnd ?></code></strong>
                </div>
                <span class="small faint"><?= TemplateRenderer::escape(implode(' · ', $slice->reasons)) ?></span>
            </div>

            <pre class="mono small" style="margin-top:10px;padding:10px;background:var(--blocked-soft);border-radius:6px;overflow-x:auto"><code><?= TemplateRenderer::escape($slice->content) ?></code></pre>
        </article>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
