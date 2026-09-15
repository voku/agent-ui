<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Run\RunProgressStep;

/**
 * Presentation copy of one Loop-owned workflow progress step.
 *
 * agent-ui deliberately keeps no lifecycle rules here. The status, owner and
 * reason are copied verbatim from agent-loop's typed projection.
 */
final readonly class WorkflowProgressStepSnapshot
{
    public function __construct(
        public string $id,
        public string $label,
        public string $status,
        public string $owner,
        public ?string $reason,
    ) {
    }

    public static function fromOwner(RunProgressStep $step): self
    {
        return new self(
            id: $step->id,
            label: $step->label,
            status: $step->status,
            owner: $step->owner,
            reason: $step->reason,
        );
    }
}
