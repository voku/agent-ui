<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

use Throwable;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\SearchIndexStore;

/**
 * Code search for the Map surface, answered entirely by agent-map.
 *
 * The UI owns no ranking, no chunking and no query language. It asks
 * `HybridSearch` over agent-map's derived `SearchIndexStore`, and when that
 * index is not usable it falls back to the structural symbol query that needs
 * no derived index at all. Both paths report which one answered, so "no result"
 * from a repository with no search index never reads like "no such code".
 *
 * The semantic channel stays deliberately off here. Enabling it means restoring
 * the embedding provider the index was written with, and the state that
 * describes it lives behind agent-map's own store metadata. Reconstructing that
 * in a consumer would put a second copy of an owner's vector-space contract in
 * the UI, so the channel is reported as `semantic_channel_unavailable` - which
 * is exactly what agent-map itself reports - until agent-map exposes a typed
 * factory for it.
 */
final readonly class CodeSearchGateway
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAXIMUM_LIMIT = 100;

    private MapArtifactLocator $locator;
    private MapProjectionGateway $structural;
    private SourceViewGateway $source;

    public function __construct(
        string $projectRoot,
        ?MapArtifactLocator $locator = null,
        ?MapProjectionGateway $structural = null,
        ?SourceViewGateway $source = null,
    ) {
        $this->locator = $locator ?? new MapArtifactLocator($projectRoot);
        $this->structural = $structural ?? new MapProjectionGateway($projectRoot);
        $this->source = $source ?? new SourceViewGateway($projectRoot, $this->locator);
    }

    public function readiness(): SearchReadinessSnapshot
    {
        $databasePath = $this->locator->paths->searchDatabase();

        if (!SearchIndexStore::supportsFts5()) {
            return new SearchReadinessSnapshot(
                status: 'unsupported',
                fts5Supported: false,
                databasePath: $databasePath,
                databaseExists: is_file($databasePath),
                chunkCount: 0,
                vectorCount: 0,
                failure: 'This PHP build has no SQLite FTS5, so agent-map cannot answer lexical code search.',
            );
        }

        if (!is_file($databasePath)) {
            return new SearchReadinessSnapshot(
                status: 'missing',
                fts5Supported: true,
                databasePath: $databasePath,
                databaseExists: false,
                chunkCount: 0,
                vectorCount: 0,
            );
        }

        try {
            $store = new SearchIndexStore($databasePath);
            $index = $this->locator->loadIndex();
            $mapSnapshot = $index?->fingerprint?->sourceDigest;
            $indexSnapshot = $store->meta('map_snapshot');
            $chunks = $store->chunkCount();
            $failures = $store->integrityFailures();

            $status = match (true) {
                $chunks === 0 => 'missing',
                $failures !== [] => 'invalid',
                $mapSnapshot !== null && $indexSnapshot !== null && $indexSnapshot !== $mapSnapshot => 'stale',
                default => 'ready',
            };

            return new SearchReadinessSnapshot(
                status: $status,
                fts5Supported: true,
                databasePath: $databasePath,
                databaseExists: true,
                chunkCount: $chunks,
                vectorCount: $store->vectorCount(),
                indexSnapshot: $indexSnapshot,
                mapSnapshot: $mapSnapshot,
                integrityFailures: $failures,
            );
        } catch (Throwable $exception) {
            return new SearchReadinessSnapshot(
                status: 'invalid',
                fts5Supported: true,
                databasePath: $databasePath,
                databaseExists: true,
                chunkCount: 0,
                vectorCount: 0,
                failure: $exception->getMessage(),
            );
        }
    }

    /**
     * @param int $previewContext how many lines of surrounding source each hit carries; 0 disables previews
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT, int $previewContext = 0): CodeSearchResult
    {
        $query = trim($query);
        $limit = max(1, min(self::MAXIMUM_LIMIT, $limit));
        if ($query === '') {
            return new CodeSearchResult(
                query: '',
                mode: 'none',
                effectiveMode: 'none',
                degraded: false,
                degradedReason: null,
                hits: [],
                limit: $limit,
                provenance: CodeSearchResult::PROVENANCE_CONSUMER,
            );
        }

        $index = $this->locator->loadIndex();
        if ($index === null) {
            return CodeSearchResult::unavailable(
                $query,
                'No code map index found. Run "vendor/bin/agent-map build" first.',
            );
        }

        $readiness = $this->readiness();
        if (!$readiness->isUsable()) {
            return CodeSearchResult::structural(
                $query,
                $this->structuralHits($query, $limit, $previewContext),
                $limit,
                $index->fingerprint?->sourceDigest,
            );
        }

        try {
            $store = new SearchIndexStore($readiness->databasePath);
            /** @var array<string, mixed> $result */
            $result = (new HybridSearch())->search($index, $store, $query, $limit);
        } catch (Throwable) {
            // A broken derived cache must not remove search from the UI: the
            // structural channel needs no cache and stays exact.
            return CodeSearchResult::structural(
                $query,
                $this->structuralHits($query, $limit, $previewContext),
                $limit,
                $index->fingerprint?->sourceDigest,
            );
        }

        return new CodeSearchResult(
            query: $query,
            mode: $this->asString($result['mode'] ?? null) ?? 'hybrid',
            effectiveMode: $this->asString($result['effective_mode'] ?? null) ?? 'unknown',
            degraded: (bool) ($result['degraded'] ?? false),
            degradedReason: $this->asString($result['degraded_reason'] ?? null),
            hits: $this->hits($result, $previewContext),
            structuralTerms: $this->stringList($result['structural_terms'] ?? null),
            mapSnapshot: $this->asString($result['map_snapshot'] ?? null),
            searchIndexSnapshot: $this->asString($result['search_index_snapshot'] ?? null),
            limit: $limit,
            provenance: CodeSearchResult::PROVENANCE_OWNER,
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<CodeSearchHit>
     */
    private function hits(array $result, int $previewContext): array
    {
        $rows = $result['results'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $hits = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $file = $this->asString($row['file_path'] ?? null);
            $symbolId = $this->asString($row['symbol_id'] ?? null);
            if ($file === null || $symbolId === null) {
                continue;
            }

            $lineStart = $this->asInt($row['start_line'] ?? null);
            $lineEnd = max($lineStart, $this->asInt($row['end_line'] ?? null));

            $hits[] = new CodeSearchHit(
                chunkId: $this->asString($row['chunk_id'] ?? null) ?? $symbolId,
                symbolId: $symbolId,
                symbolName: $this->symbolName($symbolId),
                kind: $this->symbolKind($symbolId),
                file: $file,
                lineStart: $lineStart,
                lineEnd: $lineEnd,
                score: $this->asFloat($row['rrf_score'] ?? null),
                channelRanks: $this->channelRanks($row['channel_ranks'] ?? null),
                reasons: $this->stringList($row['reasons'] ?? null),
                preview: $previewContext > 0 && $lineStart > 0
                    ? $this->source->slice($file, $lineStart, $lineEnd, $previewContext)
                    : null,
            );
        }

        return $hits;
    }

    /**
     * The fallback hits, shaped like hybrid hits so the view renders one list.
     *
     * Deliberately carrying no channel ranks, no reasons and no score: agent-map's
     * symbol query returns matches in index order, which is not a ranking, and
     * agent-map's structural channel is a different thing from this query. Filling
     * those fields with plausible-looking values would put the consumer's ordering
     * behind the owner's vocabulary.
     *
     * @return list<CodeSearchHit>
     */
    private function structuralHits(string $query, int $limit, int $previewContext): array
    {
        $hits = [];
        foreach ($this->structural->search($query, $limit) as $summary) {
            $hits[] = new CodeSearchHit(
                chunkId: $summary->id,
                symbolId: $summary->id,
                symbolName: $summary->fqn,
                kind: $summary->kind,
                file: $summary->file,
                lineStart: $summary->lineStart,
                lineEnd: $summary->lineEnd,
                score: 0.0,
                channelRanks: ['structural' => null, 'lexical' => null, 'semantic' => null],
                reasons: [],
                signature: $summary->returnType === null
                    ? '(' . implode(', ', $summary->parameters) . ')'
                    : '(' . implode(', ', $summary->parameters) . '): ' . $summary->returnType,
                preview: $previewContext > 0 && $summary->lineStart > 0
                    ? $this->source->slice($summary->file, $summary->lineStart, $summary->lineEnd, 0)
                    : null,
            );
        }

        return $hits;
    }

    /** @return array{structural: int|null, lexical: int|null, semantic: int|null} */
    private function channelRanks(mixed $ranks): array
    {
        $normalized = ['structural' => null, 'lexical' => null, 'semantic' => null];
        if (!is_array($ranks)) {
            return $normalized;
        }

        foreach (array_keys($normalized) as $channel) {
            $rank = $ranks[$channel] ?? null;
            if (is_int($rank)) {
                $normalized[$channel] = $rank;
            }
        }

        return $normalized;
    }

    /**
     * Symbol ids are `<kind>:<fqn>`, the same shape the symbol view already
     * resolves. Reading the kind from there keeps one owner format in play
     * instead of also decoding the derived chunk identity.
     */
    private function symbolKind(string $symbolId): string
    {
        $colon = strpos($symbolId, ':');

        return $colon === false ? 'symbol' : substr($symbolId, 0, $colon);
    }

    /** The kind prefix is noise in a result heading; the qualified name is the answer. */
    private function symbolName(string $symbolId): string
    {
        $colon = strpos($symbolId, ':');

        return $colon === false ? $symbolId : substr($symbolId, $colon + 1);
    }

    private function asInt(mixed $value): int
    {
        return is_int($value) || is_float($value) || is_numeric($value) ? (int) $value : 0;
    }

    private function asFloat(mixed $value): float
    {
        return is_int($value) || is_float($value) || is_numeric($value) ? (float) $value : 0.0;
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
