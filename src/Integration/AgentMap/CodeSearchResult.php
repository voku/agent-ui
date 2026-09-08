<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * A complete answer to one query, including which channels actually answered it
 * and who said so.
 *
 * agent-map reports a degraded search rather than hiding it, and the UI keeps
 * that distinction: `structural+lexical` is a different claim from
 * `structural+lexical+semantic`, and a result set that cannot name the map it
 * came from is not evidence.
 *
 * `provenance` exists because only one of the two paths here is owner-reported.
 * A hybrid answer carries agent-map's own mode, degraded reason, structural
 * terms and both snapshots. The fallback carries none of those: it is the UI
 * composing agent-map's canonical symbol query, and its mode and reason are
 * words this class chose. Presenting the second as the first would be the
 * consumer quoting itself as the owner.
 */
final readonly class CodeSearchResult
{
    /** Mode, reason, terms and snapshots came from agent-map's own result set. */
    public const string PROVENANCE_OWNER = 'agent-map';

    /** The UI composed this answer from agent-map's canonical query; the labels are its own. */
    public const string PROVENANCE_CONSUMER = 'agent-ui';

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
        public string $provenance = self::PROVENANCE_OWNER,
    ) {
    }

    /**
     * The fallback: agent-map's canonical symbol query, composed by the UI.
     *
     * `map_query` and `search_index_unavailable` are this class's words, not
     * agent-map's, which is why the result is marked as consumer provenance. The
     * map snapshot is the one genuinely owner-derived fact here and is the only
     * one carried through.
     *
     * @param list<CodeSearchHit> $hits
     */
    public static function structural(string $query, array $hits, int $limit, ?string $mapSnapshot): self
    {
        return new self(
            query: $query,
            mode: 'map_query',
            effectiveMode: 'map_query',
            degraded: true,
            degradedReason: 'search_index_unavailable',
            hits: $hits,
            mapSnapshot: $mapSnapshot,
            limit: $limit,
            provenance: self::PROVENANCE_CONSUMER,
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
            provenance: self::PROVENANCE_CONSUMER,
        );
    }

    public function isOwnerReported(): bool
    {
        return $this->provenance === self::PROVENANCE_OWNER;
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
