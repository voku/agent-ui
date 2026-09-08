<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * One ranked code location, exactly as agent-map ranked it.
 *
 * The channel ranks and reasons travel with the hit on purpose: a developer who
 * cannot see *why* a result is in front of another one has to trust the
 * ranking, and an opaque ranking is the first thing people stop using.
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

    /** @return list<string> */
    public function matchedChannels(): array
    {
        $matched = [];
        foreach ($this->channelRanks as $channel => $rank) {
            if ($rank !== null) {
                $matched[] = $channel;
            }
        }

        return $matched;
    }

    public function bestRank(): ?int
    {
        $best = null;
        foreach ($this->channelRanks as $rank) {
            if ($rank !== null && ($best === null || $rank < $best)) {
                $best = $rank;
            }
        }

        return $best;
    }

    public function lineCount(): int
    {
        return max(1, $this->lineEnd - $this->lineStart + 1);
    }
}
