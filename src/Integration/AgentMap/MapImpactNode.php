<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * One node agent-map found on a path back to the target.
 *
 * `uncertain` is preserved rather than rounded away. A dynamic call that *might*
 * reach the target is a different fact from a resolved one, and a blast radius
 * that hides the difference is the kind of confident wrong answer that costs an
 * afternoon.
 */
final readonly class MapImpactNode
{
    /**
     * @param list<string> $relationKinds
     * @param list<string> $viaNodeIds
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public int $depth,
        public array $relationKinds,
        public array $viaNodeIds,
        public bool $uncertain,
        public int $evidenceCount,
    ) {
    }
}
