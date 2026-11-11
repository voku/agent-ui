<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Execution\AttentionRequest;
use voku\AgentLoop\Execution\AttentionResolution;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowAttentionProjection;
use voku\AgentLoop\Workflow\WorkflowContractApprovalPresentation;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;
use voku\AgentLoop\Workflow\WorkflowHumanReviewCommand;

final readonly class HumanDecisionGateway
{
    private WorkflowHumanDecisionService $service;
    private TaskContractStore $contractStore;
    private WorkflowHumanDecisionProjector $approvalProjector;

    public function __construct(private string $projectRoot)
    {
        $this->service = new WorkflowHumanDecisionService($projectRoot);
        $this->contractStore = new TaskContractStore($projectRoot);
        $this->approvalProjector = new WorkflowHumanDecisionProjector($projectRoot);
    }

    public function contract(string $taskId): ?TaskContract
    {
        return $this->contractStore->find($taskId);
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $acceptanceCriteria
     */
    public function proposeContract(
        string $taskId,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        string $plannedBy,
        array $acceptanceCriteria = [],
    ): TaskContract {
        return $this->contractStore->create(
            taskId: $taskId,
            goal: $goal,
            scope: $scope,
            nonGoals: $nonGoals,
            validation: $validation,
            plannedBy: $plannedBy,
            acceptanceCriteria: $acceptanceCriteria,
        );
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $acceptanceCriteria
     */
    public function reviseContract(
        string $taskId,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        string $plannedBy,
        array $acceptanceCriteria = [],
    ): TaskContract {
        return $this->contractStore->revise(
            taskId: $taskId,
            goal: $goal,
            scope: $scope,
            nonGoals: $nonGoals,
            validation: $validation,
            plannedBy: $plannedBy,
            acceptanceCriteria: $acceptanceCriteria,
        );
    }

    /**
     * The archived Contract revisions this task's current revision replaced.
     *
     * agent-loop owns the archive and its ordering; the UI never reads the
     * history directory itself.
     *
     * @return list<TaskContract> oldest first
     */
    public function supersededRevisions(string $taskId): array
    {
        return $this->contractStore->supersededRevisions($taskId);
    }

    public function contractApprovalPresentation(
        string $taskId,
        string $nextAction,
        string $nextActionKind,
    ): ?WorkflowContractApprovalPresentation {
        return $this->approvalProjector->projectContractApprovalPresentation($taskId, $nextAction, $nextActionKind);
    }

    public function available(string $taskId): WorkflowHumanDecisionProjection
    {
        return $this->service->availableActions($taskId);
    }

    /**
     * The repository HEAD a human reviews a decision against.
     *
     * The value is only echoed back to agent-loop, which refuses the decision
     * when HEAD moved; the UI never compares commits itself.
     */
    public function headCommit(): ?string
    {
        try {
            return GitWorkTree::headCommit($this->projectRoot);
        } catch (Throwable) {
            return null;
        }
    }

    public function approve(string $taskId, string $actor, int $expectedRevision, ?string $expectedHead = null): void
    {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('A positive expected Contract revision is required.');
        }

        try {
            $this->service->approveContract($taskId, $actor, $expectedRevision, $expectedHead);
        } catch (\RuntimeException $exception) {
            throw new InvalidArgumentException('Contract approval refused by agent-loop: ' . $exception->getMessage(), previous: $exception);
        }
    }

    public function reject(string $taskId, string $actor, int $expectedRevision, string $reason, ?string $expectedHead = null): void
    {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('A positive expected Contract revision is required.');
        }

        try {
            $this->service->rejectContract($taskId, $actor, $expectedRevision, $reason, $expectedHead);
        } catch (\RuntimeException $exception) {
            throw new InvalidArgumentException('Contract rejection refused by agent-loop: ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The owner-rendered human review page, or null when agent-loop cannot
     * render one for the current task state. The HTML is a disposable view,
     * never decision authority.
     */
    public function reviewHtml(string $taskId): ?string
    {
        try {
            return (new WorkflowHumanReviewCommand($this->projectRoot))->render($taskId);
        } catch (Throwable) {
            return null;
        }
    }

    public function acknowledgeReview(string $taskId, string $reportSha256, string $actor): void
    {
        $this->service->acknowledgeReview($taskId, $reportSha256, $actor);
    }

    public function recordLearning(
        string $taskId,
        string $decision,
        string $actor,
        string $reason,
        ?string $followUpRef,
    ): void {
        $this->service->recordLearning($taskId, $decision, $actor, $reason, followUpRef: $followUpRef);
    }

    public function pendingAttention(string $taskId): ?AttentionRequest
    {
        try {
            $projection = (new ExecutionGateway($this->projectRoot))->projection($taskId);

            return $projection->attention;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The exact owner state a human reviews before resolving Attention:
     * identity, Contract revision, scope, plan digest, attempt and HEAD.
     */
    public function attentionDecision(string $taskId): ?WorkflowAttentionProjection
    {
        try {
            return $this->service->pendingAttention($taskId);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<AttentionResolution> oldest first, as agent-loop orders them */
    public function attentionResolutions(string $taskId): array
    {
        try {
            return $this->service->attentionResolutions($taskId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Resolve the Attention the human reviewed, and only that one.
     *
     * The reviewed identity comes back from the form. It must still match the
     * owner's current projection; agent-loop then re-checks the same identity
     * under its own locks, so a stale page cannot resolve a newer interruption.
     *
     * @param array{contract_revision: int, execution_plan_digest: string, attempt: int, candidate_revision: string, head_commit: ?string} $reviewed
     */
    public function resolveAttention(string $taskId, string $attentionId, string $actor, ?string $response, array $reviewed): void
    {
        $attentionId = trim($attentionId);
        if ($attentionId === '') {
            throw new InvalidArgumentException('A non-empty attention ID is required.');
        }
        $actor = trim($actor);
        if ($actor === '') {
            throw new InvalidArgumentException('A non-empty actor is required to resolve Attention.');
        }

        try {
            $current = $this->service->pendingAttention($taskId);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Attention is no longer current: ' . $exception->getMessage(), previous: $exception);
        }
        if ($current === null
            || $current->attention->id !== $attentionId
            || $current->contractRevision !== $reviewed['contract_revision']
            || !hash_equals($current->executionPlanDigest, $reviewed['execution_plan_digest'])
            || $current->attempt !== $reviewed['attempt']
            || $current->candidateRevision !== $reviewed['candidate_revision']
            || $current->headCommit !== $reviewed['head_commit']) {
            throw new InvalidArgumentException('The Attention you reviewed has changed; reload the workspace before resolving it.');
        }

        try {
            $this->service->resolveAttention($taskId, $attentionId, $actor, $response, $current);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Attention resolution refused by agent-loop: ' . $exception->getMessage(), previous: $exception);
        }
    }
}
