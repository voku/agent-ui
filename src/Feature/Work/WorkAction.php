<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Work;

use voku\AgentUi\Feature\Task\TaskContextComposer;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class WorkAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private TaskTransparencyGateway $transparency,
        private TaskContextComposer $taskContext,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        $card = $this->board->card($taskId);

        return Response::html($this->templates->render('work/index', [
            'card' => $card,
            'transparency' => $this->transparency->task($taskId),
            'task_context' => $this->taskContext->forCard($card),
        ]));
    }
}
