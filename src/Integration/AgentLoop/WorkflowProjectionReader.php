<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

/** Read-only owner projection boundary for a single task. */
interface WorkflowProjectionReader
{
    public function task(string $taskId): WorkflowSnapshot;
}
