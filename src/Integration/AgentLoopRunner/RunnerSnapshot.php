<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoopRunner;

use voku\AgentLoop\Execution\ExecutionBinding;
use voku\AgentLoopRunner\Application\RunnerStatus;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;

final readonly class RunnerSnapshot
{
    /** Observation status for a journal attempt bound to a different Run, Contract revision or plan than Loop's current execution authority. */
    public const string OBSERVATION_STALE = 'stale';

    /** Observation status for a journal attempt whose binding to current Loop authority cannot be established. */
    public const string OBSERVATION_UNVERIFIED = 'unverified';

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
        public ?string $observationRunId = null,
        public ?int $observationContractRevision = null,
        public ?string $observationPlanDigest = null,
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

    /** Process observation without any inferred workflow or control permission. */
    public static function fromObservation(?RuntimeAttempt $attempt): self
    {
        return new self(
            true,
            null,
            null,
            null,
            null,
            null,
            null,
            $attempt?->hostId,
            $attempt?->status->value,
            $attempt?->stageId,
            $attempt?->attempt,
            false,
            false,
            false,
            $attempt?->runId,
            $attempt?->contractRevision,
            $attempt?->executionPlanDigest,
        );
    }

    /**
     * The journal records a started process that has not exited: the only
     * state Runner itself treats as a live, cancellable process. A stale or
     * unverified observation is never running.
     */
    public function processRunning(): bool
    {
        return $this->observationStatus !== null
            && $this->observationStatus === AttemptStatus::ProcessStarted->value;
    }

    /**
     * The attempt ended without an accepted result: Runner recorded it as
     * failed or cancelled. Other Runner statuses render neutrally.
     */
    public function attemptInterrupted(): bool
    {
        return $this->observationStatus !== null
            && in_array($this->observationStatus, [AttemptStatus::Failed->value, AttemptStatus::Cancelled->value], true);
    }

    /**
     * Binds a journal observation to Loop's current execution authority.
     *
     * The Runner journal carries no freshness guarantee. An attempt is current
     * only when its Run id, Contract revision and execution plan digest all
     * match the current approved, non-retired governed Run (the same fields as
     * Runner's own `RuntimeAttempt::sameAuthority()`). A mismatch is reported
     * as `stale`; an unavailable binding (no approved Contract, no current or
     * a retired Run, missing or unreadable plan) as `unverified`. Neither is
     * ever presented as current execution, and both grant no control.
     */
    public function boundTo(?ExecutionBinding $current): self
    {
        if ($this->observationStatus === null || $this->observationRunId === null) {
            return $this;
        }

        if ($current === null) {
            return $this->withObservationStatus(self::OBSERVATION_UNVERIFIED);
        }

        if (!hash_equals($current->runId, $this->observationRunId)
            || $current->contractRevision !== $this->observationContractRevision
            || !hash_equals($current->executionPlanDigest, $this->observationPlanDigest ?? '')
        ) {
            return $this->withObservationStatus(self::OBSERVATION_STALE);
        }

        return $this;
    }

    private function withObservationStatus(string $status): self
    {
        return new self(
            $this->installed,
            $this->diagnostic,
            $this->profile,
            $this->currentStageId,
            $this->currentAttempt,
            $this->attentionId,
            $this->complete,
            $this->hostId,
            $status,
            $this->observationStageId,
            $this->observationAttempt,
            false,
            false,
            false,
            $this->observationRunId,
            $this->observationContractRevision,
            $this->observationPlanDigest,
        );
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
