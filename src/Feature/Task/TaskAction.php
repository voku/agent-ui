<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class TaskAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
        private HumanDecisionGateway $decisions,
        private RunnerGateway $runner,
        private CsrfTokenManager $csrf,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('task/index', [
            'card' => $this->board->card($taskId),
            'workflow' => $this->workflow->task($taskId),
            'human_decisions' => $this->decisions->available($taskId),
            'runner' => $this->runner->status($taskId),
            'csrf_token' => $this->csrf->token(),
        ]));
    }
}
