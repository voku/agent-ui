<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;

final readonly class HumanDecisionGateway
{
    private WorkflowHumanDecisionService $service;
    private TaskContractStore $contractStore;

    public function __construct(string $projectRoot)
    {
        $this->service = new WorkflowHumanDecisionService($projectRoot);
        $this->contractStore = new TaskContractStore($projectRoot);
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

    public function available(string $taskId): WorkflowHumanDecisionProjection
    {
        return $this->service->availableActions($taskId);
    }

    public function approve(string $taskId, string $actor, int $expectedRevision): void
    {
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException('A positive expected Contract revision is required.');
        }

        $contract = $this->contractStore->find($taskId);
        if ($contract === null || $contract->revision !== $expectedRevision) {
            throw new \InvalidArgumentException('Contract revision changed; reload the page before approving.');
        }

        $approveContract = new \ReflectionMethod($this->service, 'approveContract');
        $arguments = [$taskId, $actor];
        if ($approveContract->getNumberOfParameters() >= 3) {
            $arguments[] = $expectedRevision;
        }

        // Older installed agent-loop releases accept only task and actor. The
        // check above keeps those releases safe for stale form submissions;
        // newer releases receive the revision for the owner's atomic check.
        $approveContract->invokeArgs($this->service, $arguments);
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
}
