<?php
use voku\AgentUi\Integration\AgentMap\CodeSearchResult;
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\Integration\AgentMap\SearchReadinessSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{readiness: MapReadinessSnapshot, searchReadiness: SearchReadinessSnapshot, query: string, limit: int, withPreviews: bool, result: CodeSearchResult|null} $model */
$readiness = $model['readiness'];
$searchReadiness = $model['searchReadiness'];
$query = $model['query'];
$limit = $model['limit'];
$withPreviews = $model['withPreviews'];
$result = $model['result'];

$title = 'Code Search · agent-ui';
$nav = 'map';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <h1>Code Search &amp; Architecture</h1>
    <p class="lede">Search the PHP you actually have: agent-map ranks real code chunks, the map explains why each hit is here, and every result opens into verified source, callers, impact and a bounded edit context.</p>
    <p style="margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn--primary" href="/map/graph">Explore architecture graph</a>
    </p>
</div>

<p class="eyebrow">Search Code</p>
<section class="panel">
    <form class="form" method="get" action="/map">
        <div class="form__row">
            <label class="field field--wide">
                <span>Query</span>
                <input required name="q" value="<?= TemplateRenderer::escape($query) ?>" placeholder="A symbol (Router::match), a phrase from an error message, or what the code does">
            </label>
            <label class="field">
                <span>Results</span>
                <input name="limit" inputmode="numeric" value="<?= (int) $limit ?>" size="4">
            </label>
            <button class="btn btn--primary" type="submit">Search</button>
            <?php if ($query !== ''): ?>
                <a class="btn" href="/map">Clear</a>
            <?php endif; ?>
        </div>
        <?php /* The hidden 0 is submitted first so an unchecked box means "off"
                 rather than "absent"; PHP keeps the last value, so a checked box
                 still wins. No JavaScript is involved either way. */ ?>
        <input type="hidden" name="preview" value="0">
        <label class="check">
            <input type="checkbox" name="preview" value="1"<?= $withPreviews ? ' checked' : '' ?>>
            <span>Show source preview for each hit</span>
        </label>
    </form>
    <p class="note" style="margin-top:10px">
        agent-map owns the ranking. The structural channel identifies symbols you name, the lexical channel finds phrases inside code,
        and each hit lists the channel ranks it earned.
    </p>
</section>

<?php if ($result !== null): ?>
    <p class="eyebrow" style="margin-top:24px">Result Provenance</p>
    <section class="panel <?= $result->failure !== null ? 'panel--danger' : ($result->degraded ? 'panel--attention' : 'panel--accent') ?>">
        <div class="action__head">
            <div>
                <span class="pill pill--<?= $result->degraded ? 'attention' : 'ok' ?>"><?= TemplateRenderer::escape($result->effectiveMode) ?></span>
                <strong style="margin-left:8px"><?= count($result->hits) ?> hit(s)</strong>
            </div>
            <span class="small faint">
                <?= $result->isOwnerReported()
                    ? 'channel mode reported by agent-map'
                    : 'composed by agent-ui; only the map snapshot below is owner-derived' ?>
            </span>
        </div>
        <?php if ($result->isOwnerReported()): ?>
            <dl class="kv" style="margin-top:12px">
                <dt>Search mode</dt><dd><span class="mono"><?= TemplateRenderer::escape($result->mode) ?></span></dd>
                <?php if ($result->degradedReason !== null): ?>
                    <dt>Degraded</dt><dd><span class="mono"><?= TemplateRenderer::escape($result->degradedReason) ?></span></dd>
                <?php endif; ?>
                <?php if ($result->structuralTerms !== []): ?>
                    <dt>Structural terms</dt><dd><span class="mono small"><?= TemplateRenderer::escape(implode(', ', $result->structuralTerms)) ?></span></dd>
                <?php endif; ?>
                <?php if ($result->mapSnapshot !== null): ?>
                    <dt>Map snapshot</dt><dd><span class="mono small"><?= TemplateRenderer::escape(substr($result->mapSnapshot, 0, 24)) ?>…</span></dd>
                <?php endif; ?>
                <?php if ($result->searchIndexSnapshot !== null): ?>
                    <dt>Search index snapshot</dt>
                    <dd>
                        <span class="mono small"><?= TemplateRenderer::escape(substr($result->searchIndexSnapshot, 0, 24)) ?>…</span>
                        <?php if (!$result->snapshotsAgree()): ?>
                            <span class="pill pill--attention" style="margin-left:6px">behind the map</span>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>
            </dl>
        <?php else: ?>
            <dl class="kv" style="margin-top:12px">
                <dt>Answered by</dt><dd>agent-ui fallback over <span class="mono">AgentMapIndex::query()</span></dd>
                <dt>Fallback mode <span class="faint">(agent-ui label)</span></dt><dd><span class="mono"><?= TemplateRenderer::escape($result->mode) ?></span></dd>
                <?php if ($result->degradedReason !== null): ?>
                    <dt>Reason <span class="faint">(agent-ui label)</span></dt><dd><span class="mono"><?= TemplateRenderer::escape($result->degradedReason) ?></span></dd>
                <?php endif; ?>
                <?php if ($result->mapSnapshot !== null): ?>
                    <dt>Map snapshot <span class="faint">(agent-map)</span></dt><dd><span class="mono small"><?= TemplateRenderer::escape(substr($result->mapSnapshot, 0, 24)) ?>…</span></dd>
                <?php endif; ?>
                <dt>Channel ranks</dt><dd class="faint">none — the map query returns matches in index order, which is not a ranking</dd>
                <dt>Search index snapshot</dt><dd class="faint">none — no derived index answered</dd>
            </dl>
        <?php endif; ?>
        <?php if ($result->failure !== null): ?>
            <p class="note" style="margin-top:12px;color:var(--blocked)"><?= TemplateRenderer::escape($result->failure) ?></p>
        <?php elseif (!$result->isOwnerReported() && $result->hits !== []): ?>
            <p class="note" style="margin-top:12px">
                agent-map's derived chunk index did not answer, so these are symbol matches from the canonical map query, listed in index order.
                There is no channel ranking, no structural-term analysis and no search-index snapshot to report, because none was produced.
                Build the chunk index to search inside method bodies and comments, and to get agent-map's own ranked provenance:
                <code>vendor/bin/agent-map search-index build --root=.</code>
            </p>
        <?php elseif ($result->degradedReason === 'semantic_channel_unavailable'): ?>
            <p class="note" style="margin-top:12px">
                Structural and lexical channels answered. The semantic channel is reported unavailable by agent-map rather than silently substituted.
            </p>
        <?php endif; ?>
    </section>

    <p class="eyebrow" style="margin-top:24px">Results for “<?= TemplateRenderer::escape($result->query) ?>”</p>
    <?php if ($result->isEmpty()): ?>
        <section class="panel">
            <p class="empty">No code matched “<?= TemplateRenderer::escape($result->query) ?>”.</p>
            <p class="note">Try a symbol name, a literal phrase from the code, or a shorter term. A miss here is a miss in the indexed map, not proof the code does not exist.</p>
        </section>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($result->hits as $hitIndex => $hit): ?>
                <article class="panel">
                    <div class="action__head">
                        <div>
                            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($hit->kind)) ?>"><?= TemplateRenderer::escape($hit->kind) ?></span>
                            <strong style="margin-left:8px"><a href="/map/symbol?id=<?= rawurlencode($hit->symbolId) ?>"><?= TemplateRenderer::escape($hit->symbolName) ?></a></strong>
                        </div>
                        <span class="small faint">
                            <a href="/map/source?path=<?= rawurlencode($hit->file) ?>&amp;line=<?= (int) $hit->lineStart ?>"><?= TemplateRenderer::escape($hit->file) ?>:<?= (int) $hit->lineStart ?>-<?= (int) $hit->lineEnd ?></a>
                        </span>
                    </div>

                    <?php if ($hit->signature !== ''): ?>
                        <p class="small mono" style="margin:8px 0 4px;color:var(--ink-soft)"><?= TemplateRenderer::escape($hit->signature) ?></p>
                    <?php endif; ?>

                    <?php if ($hit->preview !== null): ?>
                        <?php
                        $view = $hit->preview;
                        require __DIR__ . '/_source-lines.php';
                        ?>
                    <?php endif; ?>

                    <div class="hit__meta">
                        <div class="small faint">
                            <?php if ($result->isOwnerReported()): ?>
                                <?php if ($hit->score > 0.0): ?><span class="mono">score <?= TemplateRenderer::escape(number_format($hit->score, 6)) ?></span> · <?php endif; ?>
                                <span><?= (int) $hit->lineCount() ?> line(s)</span>
                                <?php foreach ($hit->reasons as $reason): ?>
                                    · <span class="mono"><?= TemplateRenderer::escape($reason) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span>map query match #<?= (int) ($hitIndex + 1) ?> (index order, unranked)</span>
                                · <span><?= (int) $hit->lineCount() ?> line(s)</span>
                            <?php endif; ?>
                        </div>
                        <div class="hit__actions">
                            <a class="btn" href="/map/source?path=<?= rawurlencode($hit->file) ?>&amp;line=<?= (int) $hit->lineStart ?>">Source</a>
                            <a class="btn" href="/map/symbol?id=<?= rawurlencode($hit->symbolId) ?>">Symbol</a>
                            <a class="btn" href="/map/impact?target=<?= rawurlencode($hit->symbolId) ?>">Impact</a>
                            <?php if (str_starts_with($hit->symbolId, 'method:')): ?>
                                <a class="btn" href="/map/context?target=<?= rawurlencode(substr($hit->symbolId, 7)) ?>">Edit Context</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<p class="eyebrow" style="margin-top:24px">Map Readiness</p>
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
        <p class="note" style="margin-top:12px;color:var(--blocked)">Map failure: <?= TemplateRenderer::escape($readiness->failure) ?></p>
    <?php endif; ?>
</section>

<p class="eyebrow" style="margin-top:24px">Search Index Readiness</p>
<section class="panel <?= $searchReadiness->isReady() ? 'panel--accent' : ($searchReadiness->isUsable() ? 'panel--attention' : '') ?>">
    <div class="action__head">
        <div>
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($searchReadiness->status)) ?>"><?= TemplateRenderer::escape($searchReadiness->status) ?></span>
            <strong style="margin-left:8px">derived chunk index</strong>
        </div>
        <span class="small faint">FTS5: <span class="mono"><?= $searchReadiness->fts5Supported ? 'available' : 'unavailable' ?></span></span>
    </div>
    <dl class="kv" style="margin-top:12px">
        <dt>Indexed chunks</dt><dd><?= (int) $searchReadiness->chunkCount ?></dd>
        <dt>Stored vectors</dt><dd><?= (int) $searchReadiness->vectorCount ?></dd>
        <dt>Database</dt><dd><code><?= TemplateRenderer::escape($searchReadiness->databasePath) ?></code></dd>
        <?php if ($searchReadiness->indexSnapshot !== null): ?>
            <dt>Index snapshot</dt>
            <dd>
                <span class="mono small"><?= TemplateRenderer::escape(substr($searchReadiness->indexSnapshot, 0, 24)) ?>…</span>
                <?php if (!$searchReadiness->snapshotsAgree()): ?><span class="pill pill--attention" style="margin-left:6px">behind the map</span><?php endif; ?>
            </dd>
        <?php endif; ?>
    </dl>
    <?php if ($searchReadiness->status === 'missing'): ?>
        <p class="note" style="margin-top:12px">Build the chunk index to search inside method bodies, comments and error strings:
            <code>vendor/bin/agent-map search-index build --root=.</code>. Until then, search falls back to the structural symbol query.
        </p>
    <?php elseif ($searchReadiness->status === 'stale'): ?>
        <p class="note" style="margin-top:12px">The chunk index is behind the map. Refresh it with
            <code>vendor/bin/agent-map search-index refresh --root=.</code>.
        </p>
    <?php elseif ($searchReadiness->integrityFailures !== []): ?>
        <p class="note" style="margin-top:12px;color:var(--blocked)">
            agent-map reports <?= count($searchReadiness->integrityFailures) ?> integrity failure(s). Rebuild with
            <code>vendor/bin/agent-map search-index build --root=.</code>.
        </p>
    <?php elseif ($searchReadiness->failure !== null): ?>
        <p class="note" style="margin-top:12px;color:var(--blocked)"><?= TemplateRenderer::escape($searchReadiness->failure) ?></p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../layout/footer.php'; ?>
