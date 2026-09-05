<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

final readonly class MapGraphNode
{
    /**
     * @param 'module'|'subsystem'|'system'|'file' $kind
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $kind,
        public float $weight,
        public int $fileCount = 1,
        public ?string $file = null,
        public ?string $regionId = null,
    ) {
    }
}
