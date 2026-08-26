<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Handoff;

use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

/**
 * Backward-compatible route shim into the owner-backed task prompt workbench.
 */
final readonly class GuidedHandoffAction
{
    public function __construct(
        BoardProjectionGateway $board,
        WorkflowProjectionGateway $workflow,
        GuidedHandoffBuilder $builder,
        TemplateRenderer $templates,
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        return Response::redirect('/task/' . rawurlencode($taskId) . '/prompts');
    }
}
