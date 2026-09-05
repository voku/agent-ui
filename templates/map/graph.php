<?php

use voku\AgentUi\Integration\AgentMap\MapGraphNode;
use voku\AgentUi\Integration\AgentMap\MapGraphSnapshot;
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{readiness: MapReadinessSnapshot, graph: MapGraphSnapshot|null} $model */
$readiness = $model['readiness'];
$graph = $model['graph'];

$title = 'Architecture Graph · agent-ui';
$nav = 'map';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';

/** @var array<string, array{x: float, y: float}> $positions */
$positions = [];
/** @var array<string, MapGraphNode> $nodesById */
$nodesById = [];
if ($graph !== null) {
    foreach ($graph->nodes as $node) {
        $nodesById[$node->id] = $node;
    }

    $nodeCount = count($graph->nodes);
    $centerX = 500.0;
    $centerY = 350.0;
    $start = 0;

    if ($nodeCount === 1) {
        $positions[$graph->nodes[0]->id] = ['x' => $centerX, 'y' => $centerY];
        $start = 1;
    } elseif ($nodeCount > 6) {
        $positions[$graph->nodes[0]->id] = ['x' => $centerX, 'y' => $centerY];
        $start = 1;
    }

    $remaining = $nodeCount - $start;
    for ($offset = 0; $offset < $remaining; ++$offset) {
        $node = $graph->nodes[$start + $offset];
        $outer = $offset >= 10;
        $ringIndex = $outer ? $offset - 10 : $offset;
        $ringCount = $outer ? max(1, $remaining - 10) : min(10, $remaining);
        $radius = $outer ? 310.0 : 220.0;
        $angle = (-pi() / 2.0) + (2.0 * pi() * $ringIndex / $ringCount);
        $positions[$node->id] = [
            'x' => $centerX + cos($angle) * $radius,
            'y' => $centerY + sin($angle) * $radius,
        ];
    }
}
?>

<p class="crumbs"><a href="/map">Code Map</a><span>/</span><a href="/map/graph">Graph</a><?php if ($graph?->regionLabel !== null): ?><span>/</span><?= TemplateRenderer::escape($graph->regionLabel) ?><?php endif; ?></p>

<div class="page-head">
    <h1><?= TemplateRenderer::escape($graph?->title ?? 'Architecture Graph') ?></h1>
    <p class="lede">Human-readable architecture and file coupling projected from agent-map. The UI presents owner-derived regions, weights and signals; it does not invent a second architecture model.</p>
</div>

<?php if ($readiness->status === 'stale'): ?>
    <section class="panel panel--attention">
        <strong>Map is stale.</strong>
        <p class="note">This graph reflects the indexed snapshot, not necessarily the current working tree. Refresh agent-map before treating it as current evidence.</p>
    </section>
<?php endif; ?>

<?php if ($graph === null): ?>
    <section class="panel <?= $readiness->status === 'missing' ? '' : 'panel--danger' ?>">
        <h2>No usable code map</h2>
        <?php if ($readiness->status === 'missing'): ?>
            <p class="muted">Build the project map first with <code>vendor/bin/agent-map build --root=.</code></p>
        <?php else: ?>
            <p class="muted">agent-map could not provide a graph projection from the current map.</p>
            <?php if ($readiness->failure !== null): ?><p class="note"><?= TemplateRenderer::escape($readiness->failure) ?></p><?php endif; ?>
        <?php endif; ?>
    </section>
<?php else: ?>
    <?php if ($graph->breadcrumbs !== []): ?>
        <p class="crumbs" aria-label="Architecture region path">
            <a href="/map/graph">Architecture</a>
            <?php foreach ($graph->breadcrumbs as $crumb): ?>
                <span>/</span><a href="/map/graph?region=<?= rawurlencode($crumb['id']) ?>"><?= TemplateRenderer::escape($crumb['label']) ?></a>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <section class="panel panel--accent">
        <div class="action__head">
            <strong><?= TemplateRenderer::escape($graph->scope === 'architecture' ? 'Architecture overview' : 'File coupling') ?></strong>
            <span class="pill pill--neutral"><?= count($graph->nodes) ?> / <?= $graph->totalNodeCount ?> nodes</span>
            <span class="pill pill--neutral"><?= count($graph->edges) ?> / <?= $graph->totalEdgeCount ?> edges</span>
        </div>
        <dl class="kv" style="margin-top:12px">
            <dt>Source</dt><dd>agent-map discovery projection</dd>
            <dt>Map digest</dt><dd><span class="mono small"><?= TemplateRenderer::escape(substr($graph->mapDigest, 0, 20)) ?>…</span></dd>
            <dt>Ranking</dt><dd>weighted degree for nodes, coupling weight for edges</dd>
        </dl>
        <?php if ($graph->isTruncated()): ?>
            <p class="note">The visualization is intentionally bounded. It shows the strongest <?= count($graph->nodes) ?> nodes and <?= count($graph->edges) ?> connecting edges instead of asking your browser to negotiate with the entire repository at once.</p>
        <?php endif; ?>
    </section>

    <p class="eyebrow">Graph</p>
    <section class="panel" style="overflow-x:auto">
        <?php if ($graph->nodes === []): ?>
            <p class="empty">No graph nodes are available for this scope.</p>
        <?php else: ?>
            <?php
            $maximumEdgeWeight = 0.0;
            foreach ($graph->edges as $edge) {
                $maximumEdgeWeight = max($maximumEdgeWeight, $edge->weight);
            }
            ?>
            <svg viewBox="0 0 1000 700" aria-labelledby="map-graph-title map-graph-desc" style="display:block;width:100%;min-width:760px;min-height:520px;background:var(--surface-alt);border:1px solid var(--rule);border-radius:var(--radius-sm)">
                <title id="map-graph-title"><?= TemplateRenderer::escape($graph->title) ?></title>
                <desc id="map-graph-desc">A bounded agent-map coupling graph. Nodes are ordered by weighted degree and edges by coupling weight. The tables below contain the same information.</desc>

                <?php foreach ($graph->edges as $edge): ?>
                    <?php
                    $source = $positions[$edge->sourceId] ?? null;
                    $target = $positions[$edge->targetId] ?? null;
                    if ($source === null || $target === null) {
                        continue;
                    }
                    $strokeWidth = $maximumEdgeWeight > 0.0
                        ? 1.0 + (5.0 * sqrt($edge->weight / $maximumEdgeWeight))
                        : 1.0;
                    $signalParts = [];
                    foreach ($edge->signals as $signal => $signalWeight) {
                        $signalParts[] = $signal . ' ' . number_format($signalWeight, 2, '.', '');
                    }
                    $sourceLabel = $nodesById[$edge->sourceId]->label ?? $edge->sourceId;
                    $targetLabel = $nodesById[$edge->targetId]->label ?? $edge->targetId;
                    ?>
                    <line
                        x1="<?= number_format($source['x'], 2, '.', '') ?>"
                        y1="<?= number_format($source['y'], 2, '.', '') ?>"
                        x2="<?= number_format($target['x'], 2, '.', '') ?>"
                        y2="<?= number_format($target['y'], 2, '.', '') ?>"
                        stroke="var(--ink-faint)"
                        stroke-opacity="0.38"
                        stroke-width="<?= number_format($strokeWidth, 2, '.', '') ?>"
                    ><title><?= TemplateRenderer::escape($sourceLabel . ' ↔ ' . $targetLabel . ' · weight ' . number_format($edge->weight, 2, '.', '') . ($signalParts !== [] ? ' · ' . implode(', ', $signalParts) : '')) ?></title></line>
                <?php endforeach; ?>

                <?php foreach ($graph->nodes as $node): ?>
                    <?php
                    $position = $positions[$node->id] ?? ['x' => 500.0, 'y' => 350.0];
                    $displayLabel = strlen($node->label) > 22 ? substr($node->label, 0, 21) . '…' : $node->label;
                    $subtitle = $node->kind === 'file'
                        ? 'weight ' . number_format($node->weight, 1, '.', '')
                        : $node->fileCount . ' files · ' . number_format($node->weight, 1, '.', '');
                    $nodeTitle = $node->kind === 'file' && $node->file !== null
                        ? $node->file . ' · weighted degree ' . number_format($node->weight, 2, '.', '')
                        : $node->label . ' · ' . $node->fileCount . ' files · external coupling ' . number_format($node->weight, 2, '.', '');
                    $href = $graph->scope === 'architecture' && $node->regionId !== null
                        ? '/map/graph?region=' . rawurlencode($node->regionId)
                        : '/map?q=' . rawurlencode($node->file ?? $node->label);
                    ?>
                    <a href="<?= TemplateRenderer::escape($href) ?>">
                        <g>
                            <title><?= TemplateRenderer::escape($nodeTitle) ?></title>
                            <rect
                                x="<?= number_format($position['x'] - 78.0, 2, '.', '') ?>"
                                y="<?= number_format($position['y'] - 27.0, 2, '.', '') ?>"
                                width="156"
                                height="54"
                                rx="8"
                                fill="var(--surface)"
                                stroke="<?= $node->kind === 'file' ? 'var(--rule-strong)' : 'var(--accent)' ?>"
                                stroke-width="<?= $node->kind === 'file' ? '1.5' : '2' ?>"
                            />
                            <text x="<?= number_format($position['x'], 2, '.', '') ?>" y="<?= number_format($position['y'] - 3.0, 2, '.', '') ?>" text-anchor="middle" fill="var(--ink)" font-size="12" font-weight="600" font-family="var(--sans)"><?= TemplateRenderer::escape($displayLabel) ?></text>
                            <text x="<?= number_format($position['x'], 2, '.', '') ?>" y="<?= number_format($position['y'] + 14.0, 2, '.', '') ?>" text-anchor="middle" fill="var(--ink-faint)" font-size="10.5" font-family="var(--sans)"><?= TemplateRenderer::escape($subtitle) ?></text>
                        </g>
                    </a>
                <?php endforeach; ?>
            </svg>
            <p class="note"><?= $graph->scope === 'architecture' ? 'Select a region to drill down into its strongest file couplings.' : 'Select a file to search the code map for its symbols and context.' ?></p>
        <?php endif; ?>
    </section>

    <p class="eyebrow">Nodes</p>
    <section class="panel">
        <?php if ($graph->nodes === []): ?>
            <p class="empty">No nodes.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Node</th><th>Kind</th><th>Files</th><th>Weighted degree</th></tr></thead>
                    <tbody>
                    <?php foreach ($graph->nodes as $node): ?>
                        <tr>
                            <td>
                                <?php if ($graph->scope === 'architecture' && $node->regionId !== null): ?>
                                    <a href="/map/graph?region=<?= rawurlencode($node->regionId) ?>"><?= TemplateRenderer::escape($node->label) ?></a>
                                <?php elseif ($node->file !== null): ?>
                                    <a href="/map?q=<?= rawurlencode($node->file) ?>"><code><?= TemplateRenderer::escape($node->file) ?></code></a>
                                <?php else: ?>
                                    <?= TemplateRenderer::escape($node->label) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= TemplateRenderer::escape($node->kind) ?></td>
                            <td><?= $node->fileCount ?></td>
                            <td><?= number_format($node->weight, 3, '.', '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <p class="eyebrow">Edges &amp; evidence</p>
    <section class="panel">
        <?php if ($graph->edges === []): ?>
            <p class="empty">No coupling edges connect the displayed nodes.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Source</th><th>Target</th><th>Weight</th><th>Signals</th></tr></thead>
                    <tbody>
                    <?php foreach ($graph->edges as $edge): ?>
                        <?php
                        $signalParts = [];
                        foreach ($edge->signals as $signal => $signalWeight) {
                            $signalParts[] = $signal . ' ' . number_format($signalWeight, 3, '.', '');
                        }
                        ?>
                        <tr>
                            <td><?= TemplateRenderer::escape($nodesById[$edge->sourceId]->label ?? $edge->sourceId) ?></td>
                            <td><?= TemplateRenderer::escape($nodesById[$edge->targetId]->label ?? $edge->targetId) ?></td>
                            <td><?= number_format($edge->weight, 3, '.', '') ?></td>
                            <td class="small"><?= TemplateRenderer::escape(implode(', ', $signalParts)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
