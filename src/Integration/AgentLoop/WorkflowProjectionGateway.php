<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\WorkflowTaskId;

final readonly class WorkflowProjectionGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function task(string $taskId): WorkflowSnapshot
    {
        $validated = new WorkflowTaskId($taskId);
        $manifest = (new RunManifestProjector($this->projectRoot))->project($validated->value);

        return new WorkflowSnapshot(
            taskId: $manifest->taskId,
            runId: $manifest->runId,
            mode: $manifest->mode,
            state: $manifest->state,
            references: $manifest->references,
            disagreements: $manifest->disagreements,
            nextAction: $manifest->nextAction,
            nextActionKind: $manifest->nextActionKind,
        );
    }
}
