<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use RuntimeException;
use voku\AgentLearning\RunLearningDecision;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\ReviewAcknowledgement;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReportCommand;

/** Adapts owner-projected audit facts into strict UI read models. */
final readonly class AuditTrailGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function task(string $taskId): TaskAuditSnapshot
    {
        $report = (new WorkflowReportCommand($this->projectRoot))->buildReport($taskId);
        $reviewAcknowledgement = (new ReviewAcknowledgementStore($this->projectRoot))->find($taskId);
        $run = (new GovernedRunStore($this->projectRoot))->find($taskId);
        $learningDecision = $run === null
            ? null
            : (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->projectRoot, $run)))->find($run->runId);

        return $this->snapshot($report, $reviewAcknowledgement, $learningDecision);
    }

    /** @param array<string, mixed> $report */
    private function snapshot(
        array $report,
        ?ReviewAcknowledgement $reviewAcknowledgement,
        ?RunLearningDecision $learningDecision,
    ): TaskAuditSnapshot {
        $session = $this->map($report, 'session');
        $contract = $this->map($report, 'contract');
        $approval = $this->map($contract, 'approval');
        $verification = $this->map($report, 'verification');
        $recall = $this->map($report, 'recall');
        $review = $this->map($report, 'review');
        $learning = $this->map($report, 'learning');
        $validation = $this->validation($report['validation'] ?? null);
        $approvalAt = $this->nullableString($approval, 'at');
        $approvalBy = $this->nullableString($approval, 'by');

        $timeline = [];
        if ($approvalAt !== null) {
            $timeline[] = new AuditTimelineEntry(
                $approvalAt,
                'contract_approved',
                'Contract approved',
                'Revision ' . (string) ($this->nullableInt($contract, 'revision') ?? 0)
                    . ($approvalBy === null ? '' : ' by ' . $approvalBy) . '.',
            );
        }
        foreach ($validation as $item) {
            if ($item->executedAt === null) {
                continue;
            }
            $timeline[] = new AuditTimelineEntry(
                $item->executedAt,
                'validation_' . $item->status,
                'Validation ' . $item->status,
                $item->command . ' (' . $item->source . ').',
            );
        }
        if ($reviewAcknowledgement !== null) {
            $timeline[] = new AuditTimelineEntry(
                $reviewAcknowledgement->acknowledgedAt,
                'review_acknowledged',
                'Review acknowledged',
                'Exact report ' . $reviewAcknowledgement->reportSha256 . ' by '
                    . $reviewAcknowledgement->acknowledgedBy . '.',
            );
        }
        if ($learningDecision !== null) {
            $timeline[] = new AuditTimelineEntry(
                $learningDecision->decidedAt,
                'learning_decided',
                'Learning decided',
                $learningDecision->decision->value . ' by ' . $learningDecision->decidedBy . ': '
                    . $learningDecision->reason,
            );
        }
        usort(
            $timeline,
            static fn (AuditTimelineEntry $left, AuditTimelineEntry $right): int => strcmp($right->at, $left->at),
        );

        return new TaskAuditSnapshot(
            taskId: $this->string($report, 'task_id'),
            runId: $this->nullableString($report, 'run_id'),
            sessionStatus: $this->string($session, 'status'),
            sessionCount: $this->int($session, 'count'),
            activeSessionCount: $this->int($session, 'active_count'),
            contractStatus: $this->string($contract, 'status'),
            contractRevision: $this->nullableInt($contract, 'revision'),
            contractGoal: $this->nullableString($contract, 'goal'),
            approvalBy: $approvalBy,
            approvalAt: $approvalAt,
            validation: $validation,
            verificationStatus: $this->string($verification, 'status'),
            verificationPath: $this->string($verification, 'path'),
            recallStatus: $this->string($recall, 'status'),
            recallTaskFiles: $this->stringList($recall, 'task_files'),
            recallOutcomeDraft: $this->bool($recall, 'outcome_draft'),
            recallLoggedOutcomes: $this->int($recall, 'logged_outcomes'),
            reviewExists: $this->bool($review, 'exists'),
            reviewStatus: $this->nullableString($review, 'status'),
            reviewInvalid: $this->bool($review, 'invalid'),
            reviewPath: $this->string($review, 'path'),
            reviewAcknowledgedBy: $reviewAcknowledgement?->acknowledgedBy,
            reviewAcknowledgedAt: $reviewAcknowledgement?->acknowledgedAt,
            learningStatus: $this->string($learning, 'status'),
            learningFindings: $this->int($learning, 'findings'),
            learningProposals: $this->int($learning, 'proposals'),
            learningOutcomes: $this->int($learning, 'outcomes'),
            learningDecision: $learningDecision?->decision->value ?? $this->nullableString($learning, 'decision'),
            learningDecidedBy: $learningDecision?->decidedBy,
            learningDecidedAt: $learningDecision?->decidedAt,
            learningReason: $learningDecision?->reason,
            learningFindingIds: $learningDecision?->findingIds ?? [],
            learningFollowUpRef: $learningDecision?->followUpRef,
            timeline: $timeline,
        );
    }

    /** @return list<ValidationAuditSnapshot> */
    private function validation(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Workflow audit report validation must be a list.');
        }

        $result = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Workflow audit validation entry must be an object.');
            }
            $map = $this->stringMap($entry, 'validation entry');
            $result[] = new ValidationAuditSnapshot(
                $this->string($map, 'command'),
                $this->int($map, 'contract_revision'),
                $this->string($map, 'status'),
                $this->nullableInt($map, 'exit_code'),
                $this->nullableInt($map, 'duration_ms'),
                $this->nullableString($map, 'executed_at'),
                $this->string($map, 'source'),
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function map(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException('Workflow audit report field must be an object: ' . $key . '.');
        }

        return $this->stringMap($value, $key);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private function stringMap(array $value, string $field): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException('Workflow audit report object has a non-string key: ' . $field . '.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $source */
    private function string(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Workflow audit report field must be a string: ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private function nullableString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new RuntimeException('Workflow audit report field must be null or string: ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private function int(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException('Workflow audit report field must be an integer: ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private function nullableInt(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new RuntimeException('Workflow audit report field must be null or integer: ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private function bool(array $source, string $key): bool
    {
        $value = $source[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException('Workflow audit report field must be boolean: ' . $key . '.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<string>
     */
    private function stringList(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value)) {
            throw new RuntimeException('Workflow audit report field must be a string list: ' . $key . '.');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new RuntimeException('Workflow audit report list contains a non-string value: ' . $key . '.');
            }
            $result[] = $item;
        }

        return $result;
    }
}
