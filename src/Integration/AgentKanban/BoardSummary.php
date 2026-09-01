<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

final readonly class BoardSummary
{
    public function __construct(
        public string $id,
        public string $title,
        public string $projectPrefix,
        public int $cardCount = 0,
        public bool $active = false,
    ) {
    }
}
