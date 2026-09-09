<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use voku\AgentLoop\Workflow\TaskContract;

/**
 * What one Contract revision changes relative to the revision before it.
 *
 * Approval is the workflow's highest-stakes human decision, and a revision is
 * put to a reader who has already approved the one it replaces. Without the
 * earlier revision beside it, "approve revision 2" asks them to remember what
 * revision 1 said.
 *
 * The comparison is mechanical: set difference over two lists of strings that
 * agent-loop provided in the same shape. Nothing here decides whether a change
 * is significant, safe or acceptable - that is the judgement being asked for,
 * not one to pre-empt.
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

    public static function between(TaskContract $previous, TaskContract $current): self
    {
        $lists = [];
        foreach ([
            'Scope' => [$previous->scope, $current->scope],
            'Validation' => [$previous->validation, $current->validation],
            'Non-goals' => [$previous->nonGoals, $current->nonGoals],
            'Acceptance criteria' => [$previous->acceptanceCriteria, $current->acceptanceCriteria],
        ] as $label => [$before, $after]) {
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added === [] && $removed === []) {
                continue;
            }
            $lists[$label] = ['added' => $added, 'removed' => $removed];
        }

        return new self(
            fromRevision: $previous->revision,
            toRevision: $current->revision,
            previousApprovedBy: $previous->approvedBy,
            previousApprovedAt: $previous->approvedAt,
            previousGoal: $previous->goal === $current->goal ? null : $previous->goal,
            lists: $lists,
        );
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
