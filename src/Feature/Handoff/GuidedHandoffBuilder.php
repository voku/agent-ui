<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Handoff;

use LogicException;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;

final readonly class GuidedHandoffBuilder
{
    public function build(CardSnapshot $card, WorkflowSnapshot $workflow): GuidedHandoffViewModel
    {
        if ($card->id !== $workflow->taskId) {
            throw new LogicException('Board card and workflow projection refer to different tasks.');
        }

        $boardContext = json_encode([
            'task_id' => $card->id,
            'title' => $card->title,
            'summary' => $card->summary,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $prompt = implode("\n", [
            "Use this repository's agent-loop workflow.",
            'Continue task ' . $workflow->taskId . ' from its current governed state.',
            'Treat agent-loop as workflow authority. Do not invent approval, verification, review, Learning, closing, or other human authority.',
            '',
            'Canonical owner projection at handoff time:',
            'state: ' . $workflow->state,
            'next_action_kind: ' . $workflow->nextActionKind,
            'next_action: ' . $workflow->nextAction,
            '',
            'Follow that canonical next action. If it requires human authority, stop and surface the requirement instead of simulating the decision.',
            'After host-native work, return through agent-loop so owner state can advance; file changes alone are not workflow progress.',
            '',
            "Board-owned task context:\n" . $boardContext,
        ]);

        return new GuidedHandoffViewModel(
            $workflow->taskId,
            $card->title,
            $workflow->state,
            $workflow->nextAction,
            $workflow->nextActionKind,
            $prompt,
        );
    }
}
