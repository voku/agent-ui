<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class TaskAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('task/index', [
            'card' => $this->board->card($taskId),
            'workflow' => $this->workflow->task($taskId),
        ]));
    }
}
