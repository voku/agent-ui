<?php
use voku\AgentUi\Integration\AgentMap\MapImpactNode;
use voku\AgentUi\Integration\AgentMap\MapImpactSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{impact: MapImpactSnapshot, target: string, depth: int, nodes: int} $model */
$impact = $model['impact'];
$target = $model['target'];
$depth = $model['depth'];
$nodes = $model['nodes'];

$title = $impact->targetName . ' · Impact · agent-ui';
$nav = 'map';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';

$byDepth = $impact->byDepth();

/*
 * Layout only. Depth, membership and uncertainty are agent-map's answer; this
 * turns each depth ring into coordinates so the blast radius can be seen
 * instead of counted. Every drawn node is also listed below, so the picture is
 * a shortcut to the evidence and never a replacement for it.
 */
$centerX = 420.0;
$centerY = 300.0;
$ringStep = 110.0;
/** @var array<string, array{x: float, y: float, node: MapImpactNode}> $placed */
$placed = [];
foreach ($byDepth as $ringDepth => $ringNodes) {
    $count = count($ringNodes);
    $radius = $ringStep * (float) $ringDepth;
    foreach ($ringNodes as $offset => $node) {
        $angle = (-M_PI / 2.0) + (2.0 * M_PI * $offset / max(1, $count));
        $placed[$node->id] = [
            'x' => $centerX + cos($angle) * $radius,
            'y' => $centerY + sin($angle) * $radius,
            'node' => $node,
        ];
    }
}
?>
<p class="crumbs">
    <a href="/map">Code Search</a><span>/</span>
    <a href="/map/symbol?id=<?= rawurlencode($impact->targetId) ?>"><?= TemplateRenderer::escape($impact->targetName) ?></a><span>/</span>Impact
</p>

<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($impact->targetId) ?></span>
    <h1>What can notice a change here</h1>
    <p class="lede">
        agent-map walked dependency edges backwards from
        <a href="/map/source?path=<?= rawurlencode($impact->targetFile) ?>&amp;line=<?= (int) $impact->targetLineStart ?>"><code><?= TemplateRenderer::escape($impact->targetFile) ?>:<?= (int) $impact->targetLineStart ?></code></a>.
        The traversal is bounded and cycle-safe, and a path that only <em>might</em> reach the target stays marked uncertain instead of being rounded up into a fact.
    </p>
</div>

<section class="panel">
    <form class="form" method="get" action="/map/impact">
        <input type="hidden" name="target" value="<?= TemplateRenderer::escape($target) ?>">
        <div class="form__row">
            <label class="field">
                <span>Depth</span>
                <input name="depth" inputmode="numeric" value="<?= (int) $depth ?>" size="3">
            </label>
            <label class="field">
                <span>Max nodes</span>
                <input name="nodes" inputmode="numeric" value="<?= (int) $nodes ?>" size="4">
            </label>
            <button class="btn btn--primary" type="submit">Re-run</button>
            <a class="btn" href="/map/symbol?id=<?= rawurlencode($impact->targetId) ?>">Back to symbol</a>
        </div>
    </form>
</section>

<p class="eyebrow" style="margin-top:20px">Blast Radius</p>
<section class="panel panel--accent">
    <div class="action__head">
        <div>
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($impact->targetKind)) ?>"><?= TemplateRenderer::escape($impact->targetKind) ?></span>
            <strong style="margin-left:8px"><?= TemplateRenderer::escape($impact->targetName) ?></strong>
        </div>
        <span class="pill pill--neutral"><?= count($impact->impacts) ?> impacted node(s)</span>
    </div>
    <dl class="kv" style="margin-top:12px">
        <dt>Depth searched</dt><dd><?= (int) $impact->maximumDepth ?></dd>
        <dt>Node bound</dt><dd><?= (int) $impact->maximumNodes ?><?= $impact->truncated ? ' (reached — result truncated)' : '' ?></dd>
        <dt>Files touched</dt><dd><?= count($impact->files()) ?></dd>
        <dt>Uncertain paths</dt><dd><?= $impact->uncertainCount() ?></dd>
        <dt>Map digest</dt><dd><span class="mono small"><?= TemplateRenderer::escape(substr($impact->mapDigest, 0, 24)) ?>…</span></dd>
    </dl>
    <?php if ($impact->truncated): ?>
        <p class="note" style="margin-top:12px">The node bound was reached. Raise “Max nodes” to see more, or narrow the depth to read the closest ring first.</p>
    <?php endif; ?>
</section>

<?php if ($impact->isEmpty()): ?>
    <section class="panel" style="margin-top:16px">
        <p class="empty">Nothing in the indexed map depends on this node.</p>
        <p class="note">That is a fact about the map, not a licence: dynamic dispatch, reflection and code outside the indexed paths are not represented here.</p>
    </section>
<?php else: ?>
    <p class="eyebrow" style="margin-top:20px">Impact Rings</p>
    <section class="panel">
        <figure class="graph" style="margin:0">
            <svg viewBox="0 0 840 600" role="img" aria-label="Impact rings around <?= TemplateRenderer::escape($impact->targetName) ?>" class="graph__svg">
                <?php for ($ring = 1; $ring <= $impact->maximumDepth; ++$ring): ?>
                    <circle cx="<?= $centerX ?>" cy="<?= $centerY ?>" r="<?= $ringStep * $ring ?>" class="impact__ring"></circle>
                <?php endfor; ?>

                <?php foreach ($placed as $entry): ?>
                    <line x1="<?= round($centerX, 2) ?>" y1="<?= round($centerY, 2) ?>"
                          x2="<?= round($entry['x'], 2) ?>" y2="<?= round($entry['y'], 2) ?>"
                          class="impact__edge<?= $entry['node']->uncertain ? ' impact__edge--uncertain' : '' ?>"></line>
                <?php endforeach; ?>

                <?php foreach ($placed as $entry): ?>
                    <?php $node = $entry['node']; ?>
                    <a href="/map/symbol?id=<?= rawurlencode($node->id) ?>">
                        <circle cx="<?= round($entry['x'], 2) ?>" cy="<?= round($entry['y'], 2) ?>" r="7"
                                class="impact__node<?= $node->uncertain ? ' impact__node--uncertain' : '' ?>">
                            <title><?= TemplateRenderer::escape($node->name . ' — depth ' . $node->depth . ($node->uncertain ? ' (uncertain path)' : '') . ' — ' . $node->file . ':' . $node->lineStart) ?></title>
                        </circle>
                        <text x="<?= round($entry['x'], 2) ?>" y="<?= round($entry['y'] - 12.0, 2) ?>" class="impact__label" text-anchor="middle">
                            <?= TemplateRenderer::escape(mb_strimwidth($node->name, 0, 26, '…')) ?>
                        </text>
                    </a>
                <?php endforeach; ?>

                <circle cx="<?= $centerX ?>" cy="<?= $centerY ?>" r="11" class="impact__target"></circle>
                <text x="<?= $centerX ?>" y="<?= $centerY - 20.0 ?>" class="impact__label impact__label--target" text-anchor="middle">
                    <?= TemplateRenderer::escape(mb_strimwidth($impact->targetName, 0, 34, '…')) ?>
                </text>
            </svg>
            <figcaption class="small faint">
                Ring distance is agent-map's traversal depth. Dashed edges are uncertain paths. Every node links to its symbol page; the full list below carries the same facts as text.
            </figcaption>
        </figure>
    </section>

    <?php foreach ($byDepth as $ringDepth => $ringNodes): ?>
        <p class="eyebrow" style="margin-top:20px">Depth <?= (int) $ringDepth ?> (<?= count($ringNodes) ?>)</p>
        <div class="stack">
            <?php foreach ($ringNodes as $node): ?>
                <article class="panel<?= $node->uncertain ? ' panel--attention' : '' ?>">
                    <div class="action__head">
                        <div>
                            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($node->kind)) ?>"><?= TemplateRenderer::escape($node->kind) ?></span>
                            <strong style="margin-left:8px"><a href="/map/symbol?id=<?= rawurlencode($node->id) ?>"><?= TemplateRenderer::escape($node->name) ?></a></strong>
                            <?php if ($node->uncertain): ?>
                                <span class="pill pill--attention" style="margin-left:6px">uncertain path</span>
                            <?php endif; ?>
                        </div>
                        <span class="small faint">
                            <a href="/map/source?path=<?= rawurlencode($node->file) ?>&amp;line=<?= (int) $node->lineStart ?>"><?= TemplateRenderer::escape($node->file) ?>:<?= (int) $node->lineStart ?></a>
                        </span>
                    </div>
                    <div class="hit__meta">
                        <div class="small faint">
                            <span>via <?= count($node->viaNodeIds) ?> node(s)</span> ·
                            <span><?= (int) $node->evidenceCount ?> relation evidence id(s)</span> ·
                            <span class="mono"><?= TemplateRenderer::escape(implode(', ', $node->relationKinds)) ?></span>
                        </div>
                        <div class="hit__actions">
                            <a class="btn" href="/map/impact?target=<?= rawurlencode($node->id) ?>&amp;depth=<?= (int) $depth ?>&amp;nodes=<?= (int) $nodes ?>">Impact from here</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
