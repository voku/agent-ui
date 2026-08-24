<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

final readonly class BoardSnapshot
{
    /**
     * @param list<string> $lanes
     * @param list<CardSnapshot> $cards
     */
    public function __construct(
        public string $projectPrefix,
        public array $lanes,
        public array $cards,
    ) {
    }
}
