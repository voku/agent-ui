<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use LogicException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowContractApprovalPresentation;

/**
 * Read-only HTML adapter for Loop's typed approval comparison.
 *
 * The workflow owner selects the baseline and computes all Contract changes.
 * This adapter provides template fields without reproducing that logic.
 */
final readonly class ContractRevisionDelta
{
    /**
     * @param array<string, array{added: list<string>, removed: list<string>}> $lists
     *        keyed by the reader-facing name of the Contract field
     */
    private function __construct(
        public int $fromRevision,
        public int $toRevision,
        public ?string $previousApprovedBy,
        public ?string $previousApprovedAt,
        public ?string $previousGoal,
        public array $lists,
    ) {
    }

    public static function fromPresentation(WorkflowContractApprovalPresentation $presentation): self
    {
        $delta = $presentation->revisionDelta();
        if ($delta === null) {
            throw new LogicException('Cannot compare a Contract without a previous revision.');
        }

        return new self(
            fromRevision: $delta['from_revision'],
            toRevision: $delta['to_revision'],
            previousApprovedBy: $delta['previous_approved_by'],
            previousApprovedAt: $delta['previous_approved_at'],
            previousGoal: $delta['previous_goal'],
            lists: $delta['lists'],
        );
    }

    public static function between(TaskContract $previous, TaskContract $current): self
    {
        return self::fromPresentation(new WorkflowContractApprovalPresentation($current, $previous, ''));
    }

    /**
     * True when the compared fields are identical.
     *
     * A revision can exist without changing any of them, and saying "nothing
     * changed here" is a different statement from listing nothing, which would
     * read as though the comparison had not been made.
     */
    public function isEmpty(): bool
    {
        return $this->previousGoal === null && $this->lists === [];
    }

    public function previousWasApproved(): bool
    {
        return $this->previousApprovedBy !== null;
    }
}
