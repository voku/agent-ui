<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

final readonly class CardSnapshot
{
    public function __construct(
        public string $id,
        public string $title,
        public string $lane,
        public string $status,
        public string $summary,
        public string $nextAction,
        public string $validation,
        public ?int $priority,
        public ?string $assignee,
        public string $taskBrief,
    ) {
    }
}
