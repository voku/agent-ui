<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

final readonly class TaskAuditSnapshot
{
    /**
     * @param list<ValidationAuditSnapshot> $validation
     * @param list<string> $recallTaskFiles
     * @param list<string> $learningFindingIds
     * @param list<AuditTimelineEntry> $timeline
     */
    public function __construct(
        public string $taskId,
        public ?string $runId,
        public string $sessionStatus,
        public int $sessionCount,
        public int $activeSessionCount,
        public string $contractStatus,
        public ?int $contractRevision,
        public ?string $contractGoal,
        public ?string $approvalBy,
        public ?string $approvalAt,
        public array $validation,
        public string $verificationStatus,
        public string $verificationPath,
        public string $recallStatus,
        public array $recallTaskFiles,
        public bool $recallOutcomeDraft,
        public int $recallLoggedOutcomes,
        public bool $reviewExists,
        public ?string $reviewStatus,
        public bool $reviewInvalid,
        public string $reviewPath,
        public ?string $reviewAcknowledgedBy,
        public ?string $reviewAcknowledgedAt,
        public string $learningStatus,
        public int $learningFindings,
        public int $learningProposals,
        public int $learningOutcomes,
        public ?string $learningDecision,
        public ?string $learningDecidedBy,
        public ?string $learningDecidedAt,
        public ?string $learningReason,
        public array $learningFindingIds,
        public ?string $learningFollowUpRef,
        public array $timeline,
    ) {
    }
}
