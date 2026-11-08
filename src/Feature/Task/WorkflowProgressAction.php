<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

final readonly class WorkflowProgressAction
{
    private const int MAXIMUM_STEPS = 100;
    private const int MAXIMUM_REFRESH_BYTES = 262144;

    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProgressGateway $progress,
        private HumanDecisionGateway $decisions,
        private CsrfTokenManager $csrf,
        private TaskContextComposer $taskContext,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        $card = $this->board->card($taskId);
        $decisions = $this->decisions->available($taskId);
        $decisionHtml = $this->decisionHtml($card, $decisions, $this->decisions->contract($taskId));

        return Response::html($this->templates->render('task/progress', [
            'card' => $card,
            'progress' => $this->progress->task($taskId),
            'decision_html' => $decisionHtml,
            'task_context' => $this->taskContext->forCard($card),
        ]));
    }

    /** A bounded, read-only refresh of the same owner projections as the page. */
    public function json(string $taskId): Response
    {
        $headers = ['Cache-Control' => 'no-store'];
        try {
            $card = $this->board->card($taskId);
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'Task not found.'], 404, $headers);
        } catch (Throwable $exception) {
            error_log('agent-ui workflow refresh failed: ' . $exception->getMessage());

            return Response::json(['error' => 'Workflow projection unavailable.'], 503, $headers);
        }

        try {
            $progress = $this->progress->task($taskId);
            $decisions = $this->decisions->available($taskId);
            if ($progress->taskId !== $card->id || $decisions->taskId !== $card->id) {
                throw new RuntimeException('Workflow projections refer to different tasks.');
            }
            if (count($progress->steps) > self::MAXIMUM_STEPS) {
                throw new RuntimeException('Workflow progress exceeds the refresh limit.');
            }

            $steps = [];
            $seenIds = [];
            foreach ($progress->steps as $step) {
                if ($step->id === '' || isset($seenIds[$step->id])) {
                    throw new RuntimeException('Workflow progress contains an empty or duplicate step identity.');
                }
                $seenIds[$step->id] = true;
                $steps[] = [
                    'id' => $step->id,
                    'label' => $step->label,
                    'status' => $step->status,
                    'status_label' => Presentation::label($step->status),
                    'owner' => $step->owner,
                    'reason' => $step->reason,
                ];
            }

            $decisionHtml = $this->decisionHtml($card, $decisions, $this->decisions->contract($taskId));
            $response = Response::json([
                'schema_version' => 1,
                'task_id' => $progress->taskId,
                'state' => $progress->state,
                'state_label' => Presentation::label($progress->state),
                'state_tone' => Presentation::tone($progress->state),
                'next_action' => $progress->nextAction,
                'next_action_kind' => $progress->nextActionKind,
                'next_action_kind_label' => Presentation::label($progress->nextActionKind),
                'next_action_kind_tone' => Presentation::tone($progress->nextActionKind),
                'next_action_hint' => Presentation::nextActionKindHint($progress->nextActionKind),
                'command_reference' => Presentation::commandReference($progress->nextAction),
                'has_human_decision' => $decisions->actions !== [],
                'decision_html' => $decisionHtml,
                'decision_digest' => hash('sha256', $decisionHtml),
                'steps' => $steps,
            ], headers: $headers);

            if (strlen($response->body) > self::MAXIMUM_REFRESH_BYTES) {
                throw new RuntimeException('Workflow progress exceeds the refresh size limit.');
            }

            return $response;
        } catch (Throwable $exception) {
            error_log('agent-ui workflow refresh failed: ' . $exception->getMessage());

            return Response::json(['error' => 'Workflow projection unavailable.'], 503, $headers);
        }
    }

    private function decisionHtml(
        CardSnapshot $card,
        WorkflowHumanDecisionProjection $decisions,
        ?TaskContract $contract,
    ): string {
        return $this->templates->render('task/_progress-decision', [
            'card' => $card,
            'human_decisions' => $decisions,
            'contract' => $contract,
            'csrf_token' => $this->csrf->token(),
        ]);
    }
}
