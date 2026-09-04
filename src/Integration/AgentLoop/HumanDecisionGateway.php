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

    public function available(string $taskId): WorkflowHumanDecisionProjection
    {
        return $this->service->availableActions($taskId);
    }

    public function approve(string $taskId, string $actor): void
    {
        $this->service->approveContract($taskId, $actor);
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
