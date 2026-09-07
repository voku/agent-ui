<?php
use voku\AgentUi\Integration\AgentMap\SourceView;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{view: SourceView, path: string, line: int|null} $model */
$view = $model['view'];
$path = $model['path'];
$line = $model['line'];

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
    <nav class="panel source__index" aria-label="Symbols in this file">
        <?php foreach ($view->symbols as $symbol): ?>
            <a class="pill pill--neutral" href="/map/symbol?id=<?= rawurlencode($symbol['id']) ?>" title="lines <?= (int) $symbol['lineStart'] ?>–<?= (int) $symbol['lineEnd'] ?>">
                <?= TemplateRenderer::escape($symbol['name']) ?>
                <span class="faint">L<?= (int) $symbol['lineStart'] ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
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

<?php require __DIR__ . '/../layout/footer.php'; ?>
