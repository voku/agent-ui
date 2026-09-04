<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

final readonly class MapReadinessSnapshot
{
    /**
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
    ) {
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
