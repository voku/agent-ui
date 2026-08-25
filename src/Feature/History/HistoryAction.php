<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class HistoryAction
{
    public function __construct(
        private AuditTrailGateway $audit,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('history/index', [
            'audit' => $this->audit->task($taskId),
        ]));
    }
}
