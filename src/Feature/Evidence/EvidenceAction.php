<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Evidence;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class EvidenceAction
{
    public function __construct(
        private WorkflowProjectionGateway $workflow,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('evidence/index', [
            'workflow' => $this->workflow->task($taskId),
        ]));
    }
}
