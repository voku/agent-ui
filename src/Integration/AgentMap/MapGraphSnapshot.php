<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

final readonly class MapGraphSnapshot
{
    /**
     * @param 'architecture'|'region'|'files' $scope
     * @param list<MapGraphNode> $nodes
     * @param list<MapGraphEdge> $edges
     * @param list<array{id: string, label: string}> $breadcrumbs
     */
    public function __construct(
        public string $mapDigest,
        public string $scope,
        public string $title,
        public array $nodes,
        public array $edges,
        public int $totalNodeCount,
        public int $totalEdgeCount,
        public array $breadcrumbs = [],
        public ?string $regionId = null,
        public ?string $regionLabel = null,
    ) {
    }

    public function isTruncated(): bool
    {
        return count($this->nodes) < $this->totalNodeCount || count($this->edges) < $this->totalEdgeCount;
    }
}
