<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Handoff;

use voku\AgentUi\Http\Response;

/**
 * Backward-compatible route shim into the owner-backed task prompt workbench.
 */
final readonly class GuidedHandoffAction
{
    public function __invoke(string $taskId): Response
    {
        return Response::redirect('/task/' . rawurlencode($taskId) . '/prompts');
    }
}
