<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

final readonly class MapSymbolDetail
{
    /**
     * @param list<string> $parameters
     * @param list<string> $extends
     * @param list<string> $implements
     * @param list<string> $uses
     * @param list<array{name: string, visibility: string, lineStart: int, lineEnd: int, parameters: list<string>, returnType: ?string, id: string}> $methods
     * @param list<array{sourceId: string, file: string, line: int, kind: string}> $callers
     * @param list<array{targetId: string, file: string, line: int, kind: string}> $callees
     * @param list<string> $relatedSymbols
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
        public array $extends = [],
        public array $implements = [],
        public array $uses = [],
        public array $methods = [],
        public array $callers = [],
        public array $callees = [],
        public array $relatedSymbols = [],
        public ?string $docComment = null,
    ) {
    }
}
