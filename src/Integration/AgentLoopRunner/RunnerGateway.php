<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoopRunner;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoopRunner\Application\RunnerControlService;
use voku\AgentLoopRunner\Application\RunnerStatus;

final readonly class RunnerGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function status(string $taskId): RunnerSnapshot
    {
        if (!$this->installed()) {
            return RunnerSnapshot::notInstalled();
        }

        try {
            return RunnerSnapshot::fromStatus($this->service()->status($taskId));
        } catch (RuntimeException $exception) {
            return RunnerSnapshot::diagnostic($exception->getMessage());
        }
    }

    public function run(string $taskId): void
    {
        $service = $this->service();
        $this->assertAllowed($service->status($taskId), RunnerStatus::RUN);
        $service->run($taskId);
    }

    public function resume(string $taskId): void
    {
        $service = $this->service();
        $this->assertAllowed($service->status($taskId), RunnerStatus::RESUME);
        $service->resume($taskId);
    }

    public function cancel(string $taskId): void
    {
        $service = $this->service();
        $this->assertAllowed($service->status($taskId), RunnerStatus::CANCEL);
        $service->cancel($taskId);
    }

    private function installed(): bool
    {
        return class_exists(RunnerControlService::class);
    }

    private function service(): RunnerControlService
    {
        if (!$this->installed()) {
            throw new InvalidArgumentException('agent-loop-runner is not installed for this project.');
        }

        return new RunnerControlService($this->projectRoot);
    }

    private function assertAllowed(RunnerStatus $status, string $control): void
    {
        if (!$status->allows($control)) {
            throw new InvalidArgumentException('Runner control is no longer available in the current owner projection: ' . $control . '.');
        }
    }
}
