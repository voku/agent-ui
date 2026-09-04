<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

final readonly class MapSymbolSummary
{
    /**
     * @param list<string> $parameters
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public string $fqn,
        public string $file,
        public int $lineStart,
        public int $lineEnd = 0,
        public array $parameters = [],
        public ?string $returnType = null,
        public int $methodCount = 0,
        public int $callerCount = 0,
        public int $calleeCount = 0,
        public ?string $parent = null,
    ) {
    }
}
