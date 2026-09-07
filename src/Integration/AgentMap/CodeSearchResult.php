<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * A complete answer to one query, including which channels actually answered it.
 *
 * agent-map reports a degraded search rather than hiding it, and the UI keeps
 * that distinction: `structural+lexical` is a different claim from
 * `structural+lexical+semantic`, and a result set that cannot name the map it
 * came from is not evidence.
 */
final readonly class CodeSearchResult
{
    /**
     * @param list<CodeSearchHit> $hits
     * @param list<string> $structuralTerms
     */
    public function __construct(
        public string $query,
        public string $mode,
        public string $effectiveMode,
        public bool $degraded,
        public ?string $degradedReason,
        public array $hits,
        public array $structuralTerms = [],
        public ?string $mapSnapshot = null,
        public ?string $searchIndexSnapshot = null,
        public int $limit = 0,
        public ?string $failure = null,
    ) {
    }

    /** @param list<CodeSearchHit> $hits */
    public static function structural(string $query, array $hits, int $limit, ?string $mapSnapshot): self
    {
        return new self(
            query: $query,
            mode: 'structural',
            effectiveMode: 'structural',
            degraded: true,
            degradedReason: 'search_index_unavailable',
            hits: $hits,
            mapSnapshot: $mapSnapshot,
            limit: $limit,
        );
    }

    public static function unavailable(string $query, string $failure): self
    {
        return new self(
            query: $query,
            mode: 'none',
            effectiveMode: 'none',
            degraded: true,
            degradedReason: 'search_unavailable',
            hits: [],
            failure: $failure,
        );
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    public function snapshotsAgree(): bool
    {
        return $this->mapSnapshot !== null
            && $this->searchIndexSnapshot !== null
            && $this->mapSnapshot === $this->searchIndexSnapshot;
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = [];
        foreach ($this->hits as $hit) {
            $files[$hit->file] = true;
        }

        return array_keys($files);
    }
}
