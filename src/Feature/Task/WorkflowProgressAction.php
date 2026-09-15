<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class WorkflowProgressAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProgressGateway $progress,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('task/progress', [
            'card' => $this->board->card($taskId),
            'progress' => $this->progress->task($taskId),
        ]));
    }
}
