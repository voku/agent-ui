<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

final readonly class BoardSnapshot
{
    /**
     * @param list<string> $lanes
     * @param list<CardSnapshot> $cards
     * @param list<BoardSummary> $boards
     */
    public function __construct(
        public string $projectPrefix,
        public array $lanes,
        public array $cards,
        public ?string $id = null,
        public ?string $title = null,
        public array $boards = [],
    ) {
    }
}
