<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

/**
 * The few facts about a task that stay true while the reader moves between views.
 *
 * A task used to be ten pages that happened to share an id. Each one printed the
 * id and its own subject, so `Evidence & audit` could tell you what the evidence
 * was without telling you which task it belonged to, what the approved intent
 * was, or what the workflow expects next. The reader carried that across pages
 * in their head, or went back to `/task/{id}` to look it up again.
 *
 * Every field here is copied verbatim from the owner that holds it: identity and
 * lane from agent-kanban, state and next action from agent-loop. Nothing is
 * combined, ranked, or re-derived - `$nextAction` is the string Loop returned,
 * not a UI reading of it, and `$workflowState` is the state Loop projected, not
 * a conclusion drawn from the other fields.
 */
final readonly class TaskContext
{
    public function __construct(
        public string $taskId,
        public string $title,
        public string $lane,
        public string $workflowState,
        public string $nextAction,
        public string $nextActionKind,
    ) {
    }
}
