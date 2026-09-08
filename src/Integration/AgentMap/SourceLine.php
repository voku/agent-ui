<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/** One rendered source line: its real number in the file, its text, and whether it is what you came for. */
final readonly class SourceLine
{
    public function __construct(
        public int $number,
        public string $text,
        public bool $focus = false,
    ) {
    }
}
