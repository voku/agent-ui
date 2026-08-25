<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

final readonly class ValidationAuditSnapshot
{
    public function __construct(
        public string $command,
        public int $contractRevision,
        public string $status,
        public ?int $exitCode,
        public ?int $durationMs,
        public ?string $executedAt,
        public string $source,
    ) {
    }
}
