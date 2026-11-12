<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoopRunner;

interface RunnerGatewayInterface
{
    public function status(string $taskId): RunnerSnapshot;

    public function run(string $taskId): void;

    public function resume(string $taskId): void;

    public function cancel(string $taskId): void;

    public function isInstalled(): bool;
}
