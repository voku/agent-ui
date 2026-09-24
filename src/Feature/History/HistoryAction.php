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
        // One audit read per request. Both the page head and the timeline want
        // this snapshot, and asking the gateway twice meant two sets of owner
        // store reads and two chances to disagree if a run wrote in between.
        $audit = $this->audit->task($taskId);

        return Response::html($this->templates->render('history/index', [
            'audit' => $audit,
            'activity' => $this->activity->forTask($audit),
            'task_context' => $this->taskContext->forTask($taskId),
        ]));
    }
}
