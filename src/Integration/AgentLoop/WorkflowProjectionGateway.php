<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use Throwable;
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

        return $this->snapshot((new RunManifestProjector($this->projectRoot))->project($validated->value));
    }

    /**
     * Project a card list against one owner read snapshot.
     *
     * agent-loop shares its expensive observations (artifact digests, session
     * scan, Map readiness) for the duration of one batch only, so a page that
     * lists many cards no longer repeats them per card. A card agent-loop
     * cannot project maps to null, exactly as a failing task() call would be
     * skipped by the caller; one bad card never hides the others.
     *
     * @param list<string> $taskIds
     * @return array<string, WorkflowSnapshot|null> keyed by task id, in input order
     */
    public function tasks(array $taskIds): array
    {
        $valid = [];
        foreach ($taskIds as $taskId) {
            try {
                $valid[$taskId] = (new WorkflowTaskId($taskId))->value;
            } catch (Throwable) {
                // Rendered as unprojectable below.
            }
        }

        $projector = new RunManifestProjector($this->projectRoot);
        $manifests = [];
        try {
            foreach ($projector->projectMany(array_values($valid)) as $manifest) {
                $manifests[$manifest->taskId] = $manifest;
            }
        } catch (Throwable) {
            // A batch aborts on its first unprojectable card. Re-project one
            // by one so only that card is lost.
            foreach ($valid as $value) {
                try {
                    $manifests[$value] = $projector->project($value);
                } catch (Throwable) {
                    // Unprojectable card: stays null.
                }
            }
        }

        $snapshots = [];
        foreach ($taskIds as $taskId) {
            $value = $valid[$taskId] ?? null;
            $snapshots[$taskId] = $value !== null && isset($manifests[$value]) ? $this->snapshot($manifests[$value]) : null;
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
