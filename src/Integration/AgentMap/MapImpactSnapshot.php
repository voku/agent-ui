<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * agent-map's bounded answer to "what can notice if I change this".
 *
 * The traversal, the bound and the uncertainty rules are agent-map's. The UI
 * groups the result by depth for drawing and counts what it grouped; it does
 * not decide what is impacted.
 */
final readonly class MapImpactSnapshot
{
    /** @param list<MapImpactNode> $impacts */
    public function __construct(
        public string $targetId,
        public string $targetKind,
        public string $targetName,
        public string $targetFile,
        public int $targetLineStart,
        public int $targetLineEnd,
        public array $impacts,
        public int $maximumDepth,
        public int $maximumNodes,
        public bool $truncated,
        public string $mapDigest,
    ) {
    }

    /** @return array<int, list<MapImpactNode>> */
    public function byDepth(): array
    {
        $grouped = [];
        foreach ($this->impacts as $impact) {
            $grouped[$impact->depth][] = $impact;
        }
        ksort($grouped);

        return $grouped;
    }

    /** @return list<string> */
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
