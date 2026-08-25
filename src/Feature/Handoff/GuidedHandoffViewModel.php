<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Handoff;

final readonly class GuidedHandoffViewModel
{
    public function __construct(
        public string $taskId,
        public string $title,
        public string $state,
        public string $nextAction,
        public string $nextActionKind,
        public string $prompt,
    ) {
    }
}
