<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunProgressProjector;
use voku\AgentLoop\Workflow\WorkflowTaskId;

/** Read-only adapter for the Loop-owned per-task workflow progress projection. */
final readonly class WorkflowProgressGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function task(string $taskId): WorkflowProgressSnapshot
    {
        $validated = new WorkflowTaskId($taskId);
        $manifest = (new RunManifestProjector($this->projectRoot))->project($validated->value);
        $progress = (new RunProgressProjector())->projectManifest($manifest);

        return WorkflowProgressSnapshot::fromOwner($progress);
    }
}
