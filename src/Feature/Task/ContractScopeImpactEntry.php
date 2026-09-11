<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

/**
 * One declared Contract scope entry, and what agent-map says reaches it.
 *
 * `indexed` is deliberately separate from an empty impact set. A directory, a
 * `composer.json` or a file added by this very task is not something agent-map
 * has facts about, and rendering that as "nothing depends on it" would turn an
 * absent projection into a reassuring answer.
 */
final readonly class ContractScopeImpactEntry
{
    /** @param list<string> $reachedOutsideScope repository paths, already bounded */
    public function __construct(
        public string $path,
        public bool $indexed,
        public int $seedCount,
        public array $reachedOutsideScope,
        public int $reachedOutsideScopeCount,
        public int $reachedInsideScopeCount,
        public bool $uncertain,
        public bool $truncated,
    ) {
    }

    public function reachesOutsideScope(): bool
    {
        return $this->reachedOutsideScopeCount > 0;
    }

    public function listTruncated(): bool
    {
        return count($this->reachedOutsideScope) < $this->reachedOutsideScopeCount;
    }
}
