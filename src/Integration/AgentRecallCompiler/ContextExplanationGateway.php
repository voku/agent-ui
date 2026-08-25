<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentRecallCompiler;

use RuntimeException;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\WorkflowTaskId;
use voku\AgentRecallCompiler\Output\CompiledContextExplanationReader;

final readonly class ContextExplanationGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function task(string $taskId): ContextExplanationSnapshot
    {
        $validated = new WorkflowTaskId($taskId);

        try {
            $explanation = (new CompiledContextExplanationReader())->readForTask(
                RecallOutputRoot::resolve($this->projectRoot),
                $validated->value,
            );
        } catch (RuntimeException $exception) {
            error_log('agent-ui persisted Recall explanation failed validation: ' . $exception->getMessage());

            return ContextExplanationSnapshot::invalid();
        }

        return $explanation === null
            ? ContextExplanationSnapshot::missing()
            : ContextExplanationSnapshot::available($explanation);
    }
}
