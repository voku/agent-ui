<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Workflow\Transparency\TaskTransparencyProjection;
use voku\AgentLoop\Workflow\Transparency\WorkflowTransparencyService;
use voku\AgentLoop\Workflow\WorkflowTaskId;

final readonly class TaskTransparencyGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function task(string $taskId): TaskTransparencyProjection
    {
        $validated = new WorkflowTaskId($taskId);

        return (new WorkflowTransparencyService($this->projectRoot))->task($validated->value);
    }
}
