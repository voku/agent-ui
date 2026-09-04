<?php
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\Integration\AgentMap\MapSymbolSummary;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{readiness: MapReadinessSnapshot, query: string, results: list<MapSymbolSummary>} $model */
$readiness = $model['readiness'];
$query = $model['query'];
$results = $model['results'];

$title = 'Code Map · agent-ui';
$nav = 'map';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <h1>Code Map &amp; Architecture</h1>
    <p class="lede">Deterministic repository symbols, caller/callee graphs, and bounded edit contexts from agent-map. Dogfood the map to locate code, trace impact, and plan tasks.</p>
</div>

<p class="eyebrow">Map Readiness</p>
<section class="panel <?= $readiness->isReady() ? 'panel--accent' : ($readiness->status === 'stale' ? 'panel--attention' : 'panel--danger') ?>">
    <div class="action__head">
        <div>
            <span class="pill pill--<?= $readiness->isReady() ? 'ok' : ($readiness->status === 'stale' ? 'attention' : 'blocked') ?>">
                <?= TemplateRenderer::escape($readiness->status) ?>
            </span>
            <strong style="margin-left:8px"><?= TemplateRenderer::escape($readiness->backend) ?></strong>
        </div>
        <span class="small faint">Format: <span class="mono"><?= TemplateRenderer::escape($readiness->format) ?></span></span>
    </div>

    <dl class="kv" style="margin-top:12px">
        <dt>Indexed files</dt><dd><?= (int) $readiness->fileCount ?></dd>
        <dt>Total symbols</dt><dd><?= (int) $readiness->symbolCount ?> (<?= (int) $readiness->classCount ?> classes/traits, <?= (int) $readiness->methodCount ?> methods, <?= (int) $readiness->functionCount ?> functions)</dd>
        <dt>Call relations</dt><dd><?= (int) $readiness->relationCount ?></dd>
        <dt>Diagnostics</dt><dd><?= (int) $readiness->diagnosticCount ?></dd>
        <dt>Map path</dt><dd><code><?= TemplateRenderer::escape($readiness->path) ?></code></dd>
        <?php if ($readiness->snapshot !== null): ?>
            <dt>Fingerprint</dt><dd><span class="mono small"><?= TemplateRenderer::escape(substr($readiness->snapshot, 0, 16)) ?>…</span></dd>
        <?php endif; ?>
    </dl>

    <?php if ($readiness->status === 'missing'): ?>
        <p class="note" style="margin-top:12px">No map index found. Generate one with:
            <code>vendor/bin/agent-map build --root=. --paths=src,tests</code>.
        </p>
    <?php elseif ($readiness->status === 'stale'): ?>
        <p class="note" style="margin-top:12px">The map has <?= count($readiness->staleEntries) ?> stale or changed file(s). Refresh with:
            <code>vendor/bin/agent-map refresh --root=. --index=<?= TemplateRenderer::escape($readiness->path) ?></code>.
        </p>
    <?php elseif ($readiness->failure !== null): ?>
        <p class="note" style="margin-top:12px;color:var(--danger)">Map failure: <?= TemplateRenderer::escape($readiness->failure) ?></p>
    <?php endif; ?>
</section>

<p class="eyebrow" style="margin-top:24px">Symbol &amp; Code Search</p>
<section class="panel">
    <form class="form" method="get" action="/map">
        <div class="form__row">
            <label class="field field--wide">
                <span>Search Symbols</span>
                <input required name="q" value="<?= TemplateRenderer::escape($query) ?>" placeholder="Search class, interface, method, function, or keyword (e.g. Router, Application::handle, match)">
            </label>
            <button class="btn btn--primary" type="submit">Search Map</button>
            <?php if ($query !== ''): ?>
                <a class="btn" href="/map">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if ($query !== ''): ?>
    <p class="eyebrow" style="margin-top:20px">Search Results for “<?= TemplateRenderer::escape($query) ?>” (<?= count($results) ?>)</p>
    <?php if ($results === []): ?>
        <section class="panel">
            <p class="empty">No symbols found matching “<?= TemplateRenderer::escape($query) ?>”.</p>
            <p class="note">Try searching for a shorter substring or class name without namespace.</p>
        </section>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($results as $sym): ?>
                <article class="panel">
                    <div class="action__head">
                        <div>
                            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($sym->kind)) ?>"><?= TemplateRenderer::escape($sym->kind) ?></span>
                            <strong style="margin-left:8px"><a href="/map/symbol?id=<?= rawurlencode($sym->id) ?>"><?= TemplateRenderer::escape($sym->fqn) ?></a></strong>
                        </div>
                        <span class="small faint"><?= TemplateRenderer::escape($sym->file) ?>:<?= (int) $sym->lineStart ?>-<?= (int) $sym->lineEnd ?></span>
                    </div>

                    <?php if ($sym->parameters !== [] || $sym->returnType !== null): ?>
                        <p class="small mono" style="margin:8px 0 4px;color:var(--muted)">
                            (<?= TemplateRenderer::escape(implode(', ', $sym->parameters)) ?>)<?php if ($sym->returnType !== null): ?>: <?= TemplateRenderer::escape($sym->returnType) ?><?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px">
                        <div class="small faint">
                            <?php if ($sym->methodCount > 0): ?><span><?= $sym->methodCount ?> methods</span> · <?php endif; ?>
                            <span><?= $sym->callerCount ?> callers</span> · <span><?= $sym->calleeCount ?> callees</span>
                        </div>
                        <div style="display:flex;gap:6px">
                            <a class="btn" href="/map/symbol?id=<?= rawurlencode($sym->id) ?>">Inspect</a>
                            <?php if ($sym->kind === 'method'): ?>
                                <a class="btn" href="/map/context?target=<?= rawurlencode($sym->fqn) ?>">Edit Context</a>
                            <?php endif; ?>
                            <a class="btn" href="/board/new?title=<?= rawurlencode('Refactor ' . $sym->name) ?>&summary=<?= rawurlencode('Work on ' . $sym->fqn . ' in ' . $sym->file) ?>&brief=<?= rawurlencode("Target symbol: " . $sym->fqn . "\nFile: " . $sym->file . ":" . $sym->lineStart . "-" . $sym->lineEnd) ?>">+ Task</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php else: ?>
    <section class="panel" style="margin-top:16px">
        <p class="note">Enter a query above to search classes, methods, and functions across the indexed codebase, trace callers/callees, and generate edit contexts for autonomous or human execution.</p>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
