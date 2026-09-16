<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class WorkflowProgressAction
{
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

        return Response::html($this->templates->render('task/progress', [
            'card' => $card,
            'progress' => $this->progress->task($taskId),
            'human_decisions' => $this->decisions->available($taskId),
            'contract' => $this->decisions->contract($taskId),
            'csrf_token' => $this->csrf->token(),
            'task_context' => $this->taskContext->forCard($card),
        ]));
    }
}
