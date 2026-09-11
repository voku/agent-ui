<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

final readonly class MapReadinessSnapshot
{
    /**
     * @param string       $path          where this project's index belongs, whether or not it was read
     * @param string|null  $readPath      the index file that was actually parsed, null when none was
     * @param list<string> $unreadIndexes agent-map indexes under roots this reader did not choose
     *
     * @param list<array{path: string, reason: string}> $staleEntries
     */
    public function __construct(
        public string $status,
        public string $backend,
        public string $format,
        public string $path,
        public ?string $snapshot = null,
        public array $staleEntries = [],
        public int $fileCount = 0,
        public int $relationCount = 0,
        public int $diagnosticCount = 0,
        public int $symbolCount = 0,
        public int $classCount = 0,
        public int $methodCount = 0,
        public int $functionCount = 0,
        public ?string $failure = null,
        public ?string $readPath = null,
        public array $unreadIndexes = [],
    ) {
    }

    /**
     * True when another agent-map index exists that this reader did not read.
     *
     * Refreshing an index the UI never opens looks exactly like a refresh that
     * did not work, so the page has to be able to say the two apart.
     */
    public function hasUnreadIndexes(): bool
    {
        return $this->unreadIndexes !== [];
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isUsable(): bool
    {
        return $this->status === 'ready' || $this->status === 'stale';
    }
}
