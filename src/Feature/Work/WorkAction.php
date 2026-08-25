<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Work;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class WorkAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private TaskTransparencyGateway $transparency,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('work/index', [
            'card' => $this->board->card($taskId),
            'transparency' => $this->transparency->task($taskId),
        ]));
    }
}
