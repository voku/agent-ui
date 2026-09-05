<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

final readonly class MapGraphEdge
{
    /**
     * @param array<string, float> $signals
     */
    public function __construct(
        public string $sourceId,
        public string $targetId,
        public float $weight,
        public array $signals,
    ) {
    }
}
