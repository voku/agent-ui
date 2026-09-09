<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * One ranked code location, exactly as agent-map ranked it.
 *
 * `reasons` is what answers "why is this above that" for a reader: the map page
 * renders it beside the score. `channelRanks` travels as owner-reported
 * provenance rather than for display - the fallback path deliberately carries
 * none, and that difference is how a hybrid hit stays distinguishable from an
 * unranked index-order match.
 */
final readonly class CodeSearchHit
{
    /**
     * @param array{structural: int|null, lexical: int|null, semantic: int|null} $channelRanks
     * @param list<string> $reasons
     */
    public function __construct(
        public string $chunkId,
        public string $symbolId,
        public string $symbolName,
        public string $kind,
        public string $file,
        public int $lineStart,
        public int $lineEnd,
        public float $score,
        public array $channelRanks,
        public array $reasons,
        public string $signature = '',
        public ?SourceView $preview = null,
    ) {
    }

    public function lineCount(): int
    {
        return max(1, $this->lineEnd - $this->lineStart + 1);
    }
}
