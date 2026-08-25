<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;

final readonly class HumanDecisionGateway
{
    private WorkflowHumanDecisionService $service;

    public function __construct(string $projectRoot)
    {
        $this->service = new WorkflowHumanDecisionService($projectRoot);
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
