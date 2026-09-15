<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Run\RunProgressProjection;
use voku\AgentLoop\Run\RunProgressStep;

/**
 * UI read model for Loop-owned workflow progress.
 *
 * Canonical lifecycle state and routing remain owned by agent-loop. This object
 * exists only so templates do not depend on Loop internals beyond the adapter.
 */
final readonly class WorkflowProgressSnapshot
{
    /** @param list<WorkflowProgressStepSnapshot> $steps */
    public function __construct(
        public string $taskId,
        public string $state,
        public string $nextAction,
        public string $nextActionKind,
        public array $steps,
    ) {
    }

    public static function fromOwner(RunProgressProjection $projection): self
    {
        return new self(
            taskId: $projection->taskId,
            state: $projection->state,
            nextAction: $projection->nextAction,
            nextActionKind: $projection->nextActionKind,
            steps: array_map(
                static fn (RunProgressStep $step): WorkflowProgressStepSnapshot => WorkflowProgressStepSnapshot::fromOwner($step),
                $projection->steps,
            ),
        );
    }
}
