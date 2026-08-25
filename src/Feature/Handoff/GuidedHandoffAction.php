<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Handoff;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class GuidedHandoffAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
        private GuidedHandoffBuilder $builder,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::html($this->templates->render('handoff/index', [
            'handoff' => $this->builder->build(
                $this->board->card($taskId),
                $this->workflow->task($taskId),
            ),
        ]));
    }
}
