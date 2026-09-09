<?php
use voku\AgentUi\Integration\AgentMap\MapGraphSnapshot;
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\Integration\AgentMap\SourceView;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{view: SourceView, path: string, line: int|null, region: MapGraphSnapshot|null, mapReadiness: MapReadinessSnapshot} $model */
$view = $model['view'];
$path = $model['path'];
$line = $model['line'];
$region = $model['region'];
$mapReadiness = $model['mapReadiness'];

$title = basename($path) . ' · Source · agent-ui';
$nav = 'map';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/map">Code Search</a><span>/</span>Source<span>/</span><?= TemplateRenderer::escape(basename($path)) ?></p>

<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($path) ?></span>
    <h1><?= TemplateRenderer::escape(basename($path)) ?></h1>
    <p class="lede">
        Source as agent-map indexed it. The window is bounded and the file hash is checked before a single line is rendered,
        so what you read here is what the map, the callers and the impact view are talking about.
    </p>
</div>

<p class="eyebrow">Window</p>
<section class="panel <?= $view->isRendered() ? 'panel--accent' : ($view->status === 'stale' ? 'panel--attention' : 'panel--danger') ?>">
    <div class="action__head">
        <div>
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($view->status)) ?>"><?= TemplateRenderer::escape($view->status) ?></span>
            <?php if ($view->isRendered()): ?>
                <strong style="margin-left:8px">lines <?= (int) $view->lineStart ?>–<?= (int) $view->lineEnd ?></strong>
            <?php endif; ?>
        </div>
        <?php if ($view->shortSha() !== null): ?>
            <span class="small faint">source sha256 <span class="mono"><?= TemplateRenderer::escape((string) $view->shortSha()) ?>…</span></span>
        <?php endif; ?>
    </div>
    <?php if ($view->isRendered()): ?>
        <dl class="kv" style="margin-top:12px">
            <dt>Rendered</dt><dd><?= (int) $view->lineCount() ?> line(s)<?= $view->isBounded() ? ' (window bounded)' : '' ?></dd>
            <?php if ($line !== null): ?>
                <dt>Focus line</dt><dd><a href="#L<?= (int) $line ?>">L<?= (int) $line ?></a></dd>
            <?php endif; ?>
            <dt>Indexed symbols</dt><dd><?= count($view->symbols) ?></dd>
        </dl>
    <?php endif; ?>
</section>

<?php if ($view->symbols !== []): ?>
    <p class="eyebrow" style="margin-top:20px">Symbols in this file</p>
    <section class="panel" aria-label="Symbols in this file">
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Symbol</th><th>Lines</th><th>Continue</th></tr></thead>
                <tbody>
                <?php foreach ($view->symbols as $symbol): ?>
                    <tr>
                        <td><a href="/map/symbol?id=<?= rawurlencode($symbol['id']) ?>"><?= TemplateRenderer::escape($symbol['name']) ?></a></td>
                        <td class="small faint">L<?= (int) $symbol['lineStart'] ?>–<?= (int) $symbol['lineEnd'] ?></td>
                        <td>
                            <a class="btn btn--small" href="#L<?= (int) $symbol['lineStart'] ?>">Jump</a>
                            <a class="btn btn--small" href="/map/impact?target=<?= rawurlencode($symbol['id']) ?>">Impact</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<p class="eyebrow" style="margin-top:20px">Source</p>
<section class="panel">
    <?php require __DIR__ . '/_source-lines.php'; ?>
    <?php if ($view->isRendered() && $view->isBounded()): ?>
        <p class="note" style="margin-top:12px">
            This window is deliberately capped. Jump elsewhere in the file by appending a line, for example
            <code>/map/source?path=<?= TemplateRenderer::escape($path) ?>&amp;line=<?= (int) ($view->lineEnd + 1) ?></code>.
        </p>
    <?php endif; ?>
</section>

<?php if ($region !== null): ?>
    <?php // agent-map placed this exact file in this region; the coupling and
          // the ranking below are the owner's, and this page only links them. ?>
    <p class="eyebrow" style="margin-top:20px">Where this file sits</p>
    <section class="panel panel--accent">
        <div class="action__head">
            <strong><?= TemplateRenderer::escape($region->regionLabel ?? $region->title) ?></strong>
            <span class="pill pill--neutral">agent-map region</span>
            <?php if ($region->regionId !== null): ?>
                <a class="btn btn--small" href="/map/graph?region=<?= rawurlencode($region->regionId) ?>">Open in graph</a>
            <?php endif; ?>
        </div>
        <?php
        $siblings = [];
        foreach ($region->nodes as $node) {
            if ($node->file === null || $node->file === $path) {
                continue;
            }
            $siblings[] = $node;
        }
        ?>
        <?php if ($siblings === []): ?>
            <p class="note">agent-map reports no other file in this region's bounded view.</p>
        <?php else: ?>
            <p class="small faint" style="margin-top:10px">
                <?= count($siblings) ?> other file<?= count($siblings) === 1 ? '' : 's' ?> in the same region,
                ranked by the owner's weighted degree. Continue reading without starting a new search.
            </p>
            <div class="table-scroll" style="margin-top:8px">
                <table class="table">
                    <thead><tr><th>File</th><th>Weighted degree</th><th>Continue</th></tr></thead>
                    <tbody>
                    <?php foreach ($siblings as $node): ?>
                        <tr>
                            <td><code><?= TemplateRenderer::escape((string) $node->file) ?></code></td>
                            <td class="small faint"><?= number_format($node->weight, 3, '.', '') ?></td>
                            <td><a class="btn btn--small" href="/map/source?path=<?= rawurlencode((string) $node->file) ?>">Source</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php if ($region->isTruncated()): ?>
            <p class="note">The region view is bounded: <?= count($region->nodes) ?> of <?= $region->totalNodeCount ?> nodes.</p>
        <?php endif; ?>
        <?php if ($mapReadiness->status === 'stale'): ?>
            <p class="note">This placement comes from the indexed snapshot, which is behind the working tree. Refresh agent-map before treating it as current.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
