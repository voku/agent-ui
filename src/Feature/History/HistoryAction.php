<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

use voku\AgentUi\Feature\Task\TaskContextComposer;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class HistoryAction
{
    public function __construct(
        private AuditTrailGateway $audit,
        private TaskActivityComposer $activity,
        private TaskContextComposer $taskContext,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('history/index', [
            'audit' => $this->audit->task($taskId),
            'activity' => $this->activity->forTask($taskId),
            'task_context' => $this->taskContext->forTask($taskId),
        ]));
    }
}
