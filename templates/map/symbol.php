<?php
use voku\AgentUi\Integration\AgentMap\MapSymbolDetail;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{symbol: MapSymbolDetail} $model */
$symbol = $model['symbol'];

$title = $symbol->name . ' · Code Map · agent-ui';
$nav = 'map';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/map">Code Map</a><span>/</span><?= TemplateRenderer::escape($symbol->name) ?></p>

<div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
        <div style="display:flex;align-items:center;gap:8px">
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($symbol->kind)) ?>"><?= TemplateRenderer::escape($symbol->kind) ?></span>
            <span class="page-head__id"><?= TemplateRenderer::escape($symbol->id) ?></span>
        </div>
        <h1 style="margin-top:4px"><?= TemplateRenderer::escape($symbol->fqn) ?></h1>
        <p class="lede">Defined in <code><?= TemplateRenderer::escape($symbol->file) ?>:<?= (int) $symbol->lineStart ?>-<?= (int) $symbol->lineEnd ?></code></p>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn" href="/map/graph?region=<?= rawurlencode($symbol->file) ?>">Architecture Graph</a>
        <?php if ($symbol->kind === 'method'): ?>
            <a class="btn btn--primary" href="/map/context?target=<?= rawurlencode($symbol->fqn) ?>">Edit Context Plan</a>
        <?php endif; ?>
        <a class="btn btn--primary" href="/board/new?title=<?= rawurlencode('Task for ' . $symbol->name) ?>&summary=<?= rawurlencode('Work on ' . $symbol->fqn) ?>&brief=<?= rawurlencode("Target symbol: " . $symbol->fqn . "\nFile: " . $symbol->file . ":" . $symbol->lineStart . "-" . $symbol->lineEnd) ?>">+ Create Kanban Task</a>
    </div>
</div>

<p class="eyebrow">Symbol Facts</p>
<section class="panel panel--accent">
    <dl class="kv">
        <dt>Kind</dt><dd><?= TemplateRenderer::escape($symbol->kind) ?></dd>
        <dt>FQN</dt><dd><code><?= TemplateRenderer::escape($symbol->fqn) ?></code></dd>
        <dt>File</dt><dd><code><?= TemplateRenderer::escape($symbol->file) ?>:<?= (int) $symbol->lineStart ?>-<?= (int) $symbol->lineEnd ?></code></dd>
        <?php if ($symbol->parameters !== []): ?>
            <dt>Parameters</dt><dd><span class="mono small"><?= TemplateRenderer::escape(implode(', ', $symbol->parameters)) ?></span></dd>
        <?php endif; ?>
        <?php if ($symbol->returnType !== null): ?>
            <dt>Return type</dt><dd><span class="mono small"><?= TemplateRenderer::escape($symbol->returnType) ?></span></dd>
        <?php endif; ?>
        <?php if ($symbol->extends !== []): ?>
            <dt>Extends</dt><dd><span class="mono small"><?= TemplateRenderer::escape(implode(', ', $symbol->extends)) ?></span></dd>
        <?php endif; ?>
        <?php if ($symbol->implements !== []): ?>
            <dt>Implements</dt><dd><span class="mono small"><?= TemplateRenderer::escape(implode(', ', $symbol->implements)) ?></span></dd>
        <?php endif; ?>
        <?php if ($symbol->uses !== []): ?>
            <dt>Uses traits</dt><dd><span class="mono small"><?= TemplateRenderer::escape(implode(', ', $symbol->uses)) ?></span></dd>
        <?php endif; ?>
    </dl>
</section>

<?php if ($symbol->methods !== []): ?>
    <p class="eyebrow" style="margin-top:24px">Methods (<?= count($symbol->methods) ?>)</p>
    <section class="stack">
        <?php foreach ($symbol->methods as $m): ?>
            <article class="panel">
                <div class="action__head">
                    <div>
                        <span class="pill pill--muted"><?= TemplateRenderer::escape($m['visibility']) ?></span>
                        <strong style="margin-left:8px"><?= TemplateRenderer::escape($m['name']) ?></strong>
                    </div>
                    <span class="small faint">L<?= (int) $m['lineStart'] ?>-<?= (int) $m['lineEnd'] ?></span>
                </div>
                <p class="small mono" style="margin:6px 0;color:var(--muted)">
                    (<?= TemplateRenderer::escape(implode(', ', $m['parameters'])) ?>)<?php if ($m['returnType'] !== null): ?>: <?= TemplateRenderer::escape($m['returnType']) ?><?php endif; ?>
                </p>
                <div style="display:flex;gap:6px;margin-top:8px">
                    <a class="btn" href="/map/symbol?id=<?= rawurlencode($m['id']) ?>">Inspect Method</a>
                    <a class="btn" href="/map/context?target=<?= rawurlencode($symbol->fqn . '::' . $m['name']) ?>">Edit Context</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<div class="grid" style="margin-top:24px">
    <div>
        <p class="eyebrow">Callers (<?= count($symbol->callers) ?>)</p>
        <section class="panel">
            <?php if ($symbol->callers === []): ?>
                <p class="empty small">No incoming callers recorded in the map index.</p>
            <?php else: ?>
                <ul class="small" style="margin:0;padding-left:16px;line-height:1.7">
                    <?php foreach ($symbol->callers as $caller): ?>
                        <li>
                            <a href="/map/symbol?id=<?= rawurlencode($caller['sourceId']) ?>"><?= TemplateRenderer::escape($caller['sourceId']) ?></a>
                            <span class="faint">(<?= TemplateRenderer::escape($caller['file']) ?>:<?= (int) $caller['line'] ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <p class="eyebrow">Callees (<?= count($symbol->callees) ?>)</p>
        <section class="panel">
            <?php if ($symbol->callees === []): ?>
                <p class="empty small">No outgoing callees recorded in the map index.</p>
            <?php else: ?>
                <ul class="small" style="margin:0;padding-left:16px;line-height:1.7">
                    <?php foreach ($symbol->callees as $callee): ?>
                        <li>
                            <a href="/map/symbol?id=<?= rawurlencode($callee['targetId']) ?>"><?= TemplateRenderer::escape($callee['targetId']) ?></a>
                            <span class="faint">(<?= TemplateRenderer::escape($callee['file']) ?>:<?= (int) $callee['line'] ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
