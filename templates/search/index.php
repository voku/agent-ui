<?php

use voku\AgentUi\Feature\Search\SearchQuery;
use voku\AgentUi\Feature\Search\SourceResult;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{query: SearchQuery, results: list<SourceResult>} $model */
$query = $model['query'];
$results = $model['results'];

$title = ($query->raw !== '' ? $query->raw . ' · ' : '') . 'Search · agent-ui';
$nav = null;
$projectLabel = null;
$searchQuery = $query->raw;
require __DIR__ . '/../layout/header.php';

$escape = TemplateRenderer::escape(...);
$searchHref = static fn (string $text): string => '/search?q=' . rawurlencode($text);
?>
<div class="page-head">
    <h1>Search</h1>
    <p class="lede">One entry point over what the owners publish: tasks, Findings, Proposals and Guidance, symbols and code,
        prompt recipes. Results stay grouped under the owner that published them, in the order that owner publishes them —
        there is no relevance score, because nothing here is the authority on what is relevant.</p>
</div>

<section class="panel">
    <form class="search-form" role="search" action="/search" method="get">
        <label class="search-form__label" for="search-page-q">What are you looking for?</label>
        <div class="search-form__row">
            <input id="search-page-q" class="search-form__input" type="search" name="q" value="<?= $escape($query->raw) ?>" autocomplete="off" spellcheck="false" placeholder="UI-58, provenance, WorkflowProgressProjector…">
            <button class="btn btn--primary" type="submit">Search</button>
        </div>
    </form>
    <p class="note">Start with a scope to narrow it:
        <?php foreach (SearchQuery::SCOPES as $index => $scope): ?><?= $index > 0 ? ', ' : ' ' ?><code><?= $escape($scope) ?></code><?php endforeach; ?>.
        <code>find</code> searches everywhere. A leading <code>/</code> is optional. Any other first word is just part of what you are looking for.</p>
</section>

<?php if ($query->tooLong): ?>
    <section class="panel panel--attention" role="status">
        <p>That query is longer than <?= SearchQuery::MAXIMUM_LENGTH ?> characters, so it was not searched. Search for the identifier or phrase you actually need.</p>
    </section>
<?php elseif ($query->isEmpty()): ?>
    <section class="panel"><p class="empty">Type an identifier, a title or a few words above. Nothing is searched until you do.</p></section>
<?php elseif ($query->needsTerm()): ?>
    <section class="panel"><p class="empty"><?= $escape(ucfirst((string) $query->scope)) ?> search needs something to look for — for example <code><?= $escape($query->scope) ?> WorkflowProgressProjector</code>. Listing every <?= $escape((string) $query->scope) ?> in the index is not a search.</p></section>
<?php else: ?>
    <?php
    /*
     * Sources that were searched and held nothing fold into one line, so a combined
     * search does not bury its one real answer under four empty panels. They stay
     * named, with their owner: "searched, nothing there" is a different statement
     * from "unavailable", which keeps its own panel and its reason.
     */
    $empty = array_values(array_filter($results, static fn (SourceResult $r): bool => $r->status === SourceResult::NO_MATCHES));
    $shown = array_values(array_filter($results, static fn (SourceResult $r): bool => $r->status !== SourceResult::NO_MATCHES));
    ?>
    <?php foreach ($shown as $result): ?>
        <?php
        $heading = 'search-' . $result->scope;
        $scoped = $searchHref($result->scope . ' ' . $query->term);
        ?>
        <section class="panel search-group" aria-labelledby="<?= $escape($heading) ?>">
            <h2 id="<?= $escape($heading) ?>"><?= $escape($result->label) ?></h2>
            <p class="provenance provenance--authority"><?= $escape($result->owner) ?></p>
            <?php if ($result->caveat !== null): ?>
                <p class="search-group__caveat" role="note"><strong>Possibly incomplete.</strong> <?= $escape($result->caveat) ?></p>
            <?php endif; ?>

            <?php if ($result->status === SourceResult::UNAVAILABLE): ?>
                <?php /* Not "no matches": the owner could not be asked, which says nothing about whether a match exists. */ ?>
                <p class="search-group__state search-group__state--unavailable" role="status"><strong>Unavailable — not searched.</strong>
                    <?= $escape((string) $result->reason) ?> This is not the same as no matches.</p>
            <?php else: ?>
                <p class="note search-group__count">
                    <?php if ($result->isTruncated()): ?>
                        Showing <?= count($result->hits) ?> of <?= $result->exact ? $result->matched : 'more than ' . ($result->matched - 1) ?>
                        <?php if ($query->scope === null): ?>
                            — <a href="<?= $escape($scoped) ?>">search only <?= $escape(strtolower($result->label)) ?></a> to see more.
                        <?php else: ?>
                            <?php /* Already scoped: the "scoped" link would be this very page. */ ?>
                            — this is already a search of <?= $escape(strtolower($result->label)) ?> alone, so narrow the term to see the rest.
                        <?php endif; ?>
                    <?php else: ?>
                        <?= $result->matched ?> <?= $result->matched === 1 ? 'match' : 'matches' ?>.
                    <?php endif; ?>
                </p>
                <ul class="search-hits">
                    <?php foreach ($result->hits as $hit): ?>
                        <li class="search-hit">
                            <a class="search-hit__id mono" href="<?= $escape($hit->href) ?>"><?= $escape($hit->id) ?></a>
                            <?php if ($hit->title !== '' && $hit->title !== $hit->id): ?><span class="search-hit__title"><?= $escape($hit->title) ?></span><?php endif; ?>
                            <span class="search-hit__detail"><?= $escape($hit->detail) ?></span>
                            <?php if ($hit->matchedIn !== []): ?><span class="search-hit__why">matched in <?= $escape(implode(', ', $hit->matchedIn)) ?></span><?php endif; ?>
                            <?php foreach ($hit->alsoAt as $also): ?><a class="search-hit__also" href="<?= $escape($also['href']) ?>"><?= $escape($also['label']) ?></a><?php endforeach; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($empty !== []): ?>
        <section class="panel search-empty" aria-labelledby="search-empty">
            <h2 id="search-empty">Searched, no matches</h2>
            <ul class="search-empty__list">
                <?php foreach ($empty as $result): ?>
                    <li><strong><?= $escape($result->label) ?></strong> <span class="search-empty__owner"><?= $escape($result->owner) ?></span><?php if ($result->caveat !== null): ?> <span class="search-empty__caveat">— possibly incomplete: <?= $escape($result->caveat) ?></span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($shown === [] && $empty === []): ?>
        <section class="panel"><p class="empty">Nothing was searched for that scope.</p></section>
    <?php endif; ?>

    <?php if ($query->scope === null && $query->term !== ''): ?>
        <p class="note">Code chunks are not searched in the combined view — the symbols above come from agent-map's index.
            To search chunk text and see previews, use <a href="<?= $escape($searchHref('code ' . $query->term)) ?>"><code>code <?= $escape($query->term) ?></code></a>.</p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
