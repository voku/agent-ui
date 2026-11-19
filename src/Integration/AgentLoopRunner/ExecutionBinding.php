<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoopRunner;

/** Loop's current execution identity for a task, used only to judge Runner journal freshness. */
final readonly class ExecutionBinding
{
    public function __construct(
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
    ) {
    }
}
