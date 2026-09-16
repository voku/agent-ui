<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Context;

use voku\AgentUi\Feature\Task\TaskContextComposer;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class ContextAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private ContextExplanationGateway $context,
        private TaskTransparencyGateway $transparency,
        private TaskContextComposer $taskContext,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        $card = $this->board->card($taskId);

        return Response::html($this->templates->render('context/index', [
            'card' => $card,
            'context' => $this->context->task($taskId),
            'coverage' => $this->transparency->task($taskId)->context,
            'task_context' => $this->taskContext->forCard($card),
        ]));
    }
}
