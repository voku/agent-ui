<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentLoop\Workflow\WorkflowPromptService;

/**
 * Thin UI adapter over agent-loop workflow prompt authority.
 */
final readonly class WorkflowPromptGateway
{
    private WorkflowPromptService $service;

    public function __construct(string $projectRoot)
    {
        $this->service = new WorkflowPromptService($projectRoot);
    }

    public function start(string $taskId): WorkflowPromptEnvelope
    {
        return $this->service->startTask($taskId);
    }

    public function continue(string $taskId): WorkflowPromptEnvelope
    {
        return $this->service->continueTask($taskId);
    }
}
