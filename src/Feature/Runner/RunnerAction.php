<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Runner;

use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
use voku\AgentUi\Security\CsrfTokenManager;

final readonly class RunnerAction
{
    public function __construct(
        private RunnerGateway $runner,
        private CsrfTokenManager $csrf,
    ) {
    }

    /** @param 'runner_run'|'runner_resume'|'runner_cancel' $route */
    public function __invoke(string $taskId, string $route, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        match ($route) {
            'runner_run' => $this->runner->run($taskId),
            'runner_resume' => $this->runner->resume($taskId),
            'runner_cancel' => $this->runner->cancel($taskId),
        };

        return Response::redirect('/task/' . rawurlencode($taskId));
    }
}
