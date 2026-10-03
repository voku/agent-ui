<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use InvalidArgumentException;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;

/**
 * Reads the owners a persistent task header needs, and composes nothing else.
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
        private HumanDecisionGateway $decisions,
    ) {
    }

    public function forTask(string $taskId): TaskContext
    {
        $card = $this->board->card($taskId);

        return $this->fromOwnerSnapshots(
            $card,
            $this->workflow->task($taskId),
            $this->decisions->available($taskId),
        );
    }

    /**
     * Reuse a fresh workflow projection the caller already needed for this request.
     *
     * The decision projection remains an owner read here; only the duplicate
     * workflow projection is removed.
     */
    public function forTaskWithWorkflow(string $taskId, WorkflowSnapshot $workflow): TaskContext
    {
        $card = $this->board->card($taskId);

        return $this->fromOwnerSnapshots(
            $card,
            $workflow,
            $this->decisions->available($taskId),
        );
    }

    public function forCard(CardSnapshot $card): TaskContext
    {
        return $this->fromOwnerSnapshots(
            $card,
            $this->workflow->task($card->id),
            $this->decisions->available($card->id),
        );
    }

    /**
     * Compose one TaskContext from owner snapshots already read by the caller.
     *
     * This is request-local value reuse, not caching: every caller decides when
     * to read the owners, and nothing survives the request or this method call.
     */
    public function fromOwnerSnapshots(
        CardSnapshot $card,
        WorkflowSnapshot $workflow,
        WorkflowHumanDecisionProjection $humanDecisions,
    ): TaskContext {
        if ($card->id !== $workflow->taskId || $card->id !== $humanDecisions->taskId) {
            throw new InvalidArgumentException(sprintf(
                'TaskContext owner snapshots must describe one task: card=%s workflow=%s decisions=%s.',
                $card->id,
                $workflow->taskId,
                $humanDecisions->taskId,
            ));
        }

        return new TaskContext(
            taskId: $card->id,
            title: $card->title,
            lane: $card->lane,
            workflowState: $workflow->state,
            nextAction: $workflow->nextAction,
            nextActionKind: $workflow->nextActionKind,
            awaitsHumanDecision: $humanDecisions->actions !== [],
        );
    }
}
