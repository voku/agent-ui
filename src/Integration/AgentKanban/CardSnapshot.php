<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

final readonly class CardSnapshot
{
    /**
     * @param list<string> $allowedTransitions
     */
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
        public string $revision = '',
        public ?string $claimActor = null,
        public array $allowedTransitions = [],
        public ?string $boardId = null,
        /**
         * When agent-kanban says the card was written, or null when it cannot say.
         *
         * The owner already models this as nullable - a card whose file carries no
         * parsable created date has no creation time as far as the board is
         * concerned - so the UI carries the null through rather than reaching for
         * the file's mtime.
         */
        public ?string $createdAt = null,
    ) {
    }
}
