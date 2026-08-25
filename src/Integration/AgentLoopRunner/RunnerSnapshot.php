<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoopRunner;

use voku\AgentLoopRunner\Application\RunnerStatus;

final readonly class RunnerSnapshot
{
    public function __construct(
        public bool $installed,
        public ?string $diagnostic,
        public ?string $profile,
        public ?string $currentStageId,
        public ?int $currentAttempt,
        public ?string $attentionId,
        public ?bool $complete,
        public ?string $hostId,
        public ?string $observationStatus,
        public ?string $observationStageId,
        public ?int $observationAttempt,
        public bool $allowRun,
        public bool $allowResume,
        public bool $allowCancel,
    ) {
    }

    public static function notInstalled(): self
    {
        return new self(false, null, null, null, null, null, null, null, null, null, null, false, false, false);
    }

    public static function diagnostic(string $message): self
    {
        return new self(true, $message, null, null, null, null, null, null, null, null, null, false, false, false);
    }

    public static function fromStatus(RunnerStatus $status): self
    {
        $observation = $status->observation;

        return new self(
            true,
            null,
            $status->authority->profile->value,
            $status->authority->currentStageId,
            $status->authority->currentAttempt,
            $status->authority->attention?->id,
            $status->authority->complete(),
            $observation?->hostId,
            $observation?->status->value,
            $observation?->stageId,
            $observation?->attempt,
            $status->allows(RunnerStatus::RUN),
            $status->allows(RunnerStatus::RESUME),
            $status->allows(RunnerStatus::CANCEL),
        );
    }
}
