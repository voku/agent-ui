<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * agent-map's bounded answer to "what outside this file can notice a change to it".
 *
 * `seedCount` is why an empty impact set is readable: no declarations means the
 * map has nothing to traverse from, which is a different fact from declarations
 * nothing depends on. Collapsing the two would let an unindexed shape read as an
 * isolated one.
 */
final readonly class MapFileImpactSnapshot
{
    /** @param list<MapImpactNode> $impacts */
    public function __construct(
        public string $path,
        public int $seedCount,
        public array $impacts,
        public int $maximumDepth,
        public int $maximumNodes,
        public bool $truncated,
        public string $mapDigest,
    ) {
    }

    /** @return list<string> distinct files the impact set covers, in the owner's order */
    public function files(): array
    {
        $files = [];
        foreach ($this->impacts as $impact) {
            $files[$impact->file] = true;
        }

        return array_keys($files);
    }

    public function uncertainCount(): int
    {
        $count = 0;
        foreach ($this->impacts as $impact) {
            if ($impact->uncertain) {
                ++$count;
            }
        }

        return $count;
    }

    public function isEmpty(): bool
    {
        return $this->impacts === [];
    }
}
