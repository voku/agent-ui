<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

final readonly class AuditTimelineEntry
{
    public function __construct(
        public string $at,
        public string $kind,
        public string $title,
        public string $detail,
    ) {
    }
}
