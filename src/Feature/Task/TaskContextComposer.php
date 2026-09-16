<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;

/**
 * Reads the two owners a persistent task header needs, and composes nothing else.
 *
 * The composition is deliberately dull: take identity from the board, take state
 * and next action from Loop, copy both across. Both call sites exist because most
 * task views already hold the card they were rendered from, and re-reading the
 * board for it would be work the caller already did.
 */
final readonly class TaskContextComposer
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
    ) {
    }

    public function forTask(string $taskId): TaskContext
    {
        return $this->forCard($this->board->card($taskId));
    }

    public function forCard(CardSnapshot $card): TaskContext
    {
        $workflow = $this->workflow->task($card->id);

        return new TaskContext(
            taskId: $card->id,
            title: $card->title,
            lane: $card->lane,
            workflowState: $workflow->state,
            nextAction: $workflow->nextAction,
            nextActionKind: $workflow->nextActionKind,
        );
    }
}
