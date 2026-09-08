<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * Whether agent-map's derived hybrid-search index can answer a query right now.
 *
 * The derived index is a cache: it can be missing, behind the map, or unusable
 * because the PHP build has no FTS5. Each of those is a different sentence to
 * the developer and a different command to run, so they are kept apart instead
 * of collapsed into one "search unavailable".
 */
final readonly class SearchReadinessSnapshot
{
    /** @param list<string> $integrityFailures */
    public function __construct(
        public string $status,
        public bool $fts5Supported,
        public string $databasePath,
        public bool $databaseExists,
        public int $chunkCount,
        public int $vectorCount,
        public ?string $indexSnapshot = null,
        public ?string $mapSnapshot = null,
        public array $integrityFailures = [],
        public ?string $failure = null,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->status === 'ready' || $this->status === 'stale';
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function snapshotsAgree(): bool
    {
        return $this->indexSnapshot !== null
            && $this->mapSnapshot !== null
            && $this->indexSnapshot === $this->mapSnapshot;
    }
}
