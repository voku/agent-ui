<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

/**
 * UI read model projected verbatim from agent-loop-owned lifecycle output.
 *
 * @phpstan-type Reference array<string, mixed>
 * @phpstan-type Disagreement array{code: string, owner: string, message: string}
 */
final readonly class WorkflowSnapshot
{
    /**
     * @param array<string, Reference> $references
     * @param list<Disagreement> $disagreements
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public string $mode,
        public string $state,
        public array $references,
        public array $disagreements,
        public string $nextAction,
        public string $nextActionKind,
    ) {
    }
}
