<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Run\RunManifest;
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

        return $this->snapshot($manifest);
    }

    /**
     * @param list<string> $taskIds
     * @return array<string, WorkflowSnapshot>
     */
    public function tasks(array $taskIds): array
    {
        $validatedTaskIds = [];
        foreach ($taskIds as $taskId) {
            $validatedTaskIds[] = (new WorkflowTaskId($taskId))->value;
        }

        $snapshots = [];
        $projector = new RunManifestProjector($this->projectRoot);
        foreach ($projector->projectMany($validatedTaskIds) as $manifest) {
            $snapshots[$manifest->taskId] = $this->snapshot($manifest);
        }

        return $snapshots;
    }

    private function snapshot(RunManifest $manifest): WorkflowSnapshot
    {
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
