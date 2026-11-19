<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoopRunner;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunRetirementStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

/**
 * Reads Loop's current execution identity through Loop's typed read-only
 * stores. It never prepares a Run, plan or state; anything it cannot prove
 * current yields null, so callers treat the Runner observation as unverified.
 */
final readonly class ExecutionBindingReader
{
    public function __construct(private string $projectRoot)
    {
    }

    public function current(string $taskId): ?ExecutionBinding
    {
        try {
            $contract = (new TaskContractStore($this->projectRoot))->find($taskId);
            if ($contract === null || $contract->status !== TaskContract::APPROVED) {
                return null;
            }

            $run = (new GovernedRunStore($this->projectRoot))->findForContract($contract);
            if ($run === null || (new RunRetirementStore($this->projectRoot))->findForRun($run) !== null) {
                return null;
            }

            $plan = (new ExecutionPlanStore($this->projectRoot))->find($taskId);
            if ($plan === null
                || $plan->taskId !== $taskId
                || $plan->runId !== $run->runId
                || $plan->contractRevision !== $contract->revision
                || $plan->contractSource !== $run->contractSource
            ) {
                return null;
            }

            return new ExecutionBinding($run->runId, $contract->revision, $plan->digest());
        } catch (RuntimeException|InvalidArgumentException) {
            return null;
        }
    }
}
