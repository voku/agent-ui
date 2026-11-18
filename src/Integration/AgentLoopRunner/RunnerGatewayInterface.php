<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoopRunner;

interface RunnerGatewayInterface
{
    /** Owner journal observation only; never grants execution authority. */
    /**
     * Read-only Runner journal observation, bound to Loop's current execution
     * authority (stale / unverified when it cannot be shown as current). Never
     * prepares Loop execution artifacts and never grants a control.
     */
    public function observe(string $taskId): RunnerSnapshot;

    public function status(string $taskId): RunnerSnapshot;

    public function run(string $taskId): void;

    public function resume(string $taskId): void;

    public function cancel(string $taskId): void;

    public function isInstalled(): bool;
}
