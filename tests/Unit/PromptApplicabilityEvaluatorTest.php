<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Feature\PromptWorkbench\PromptApplicabilityEvaluator;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;

final class PromptApplicabilityEvaluatorTest extends TestCase
{
    public function testExecuteRecipeIsBlockedWithoutMutationAuthority(): void
    {
        $errors = (new PromptApplicabilityEvaluator())->errors(
            $this->recipe('test-driven-development', OperatingPromptRecipe::PURPOSE_EXECUTE),
            $this->workflow(WorkflowPromptEnvelope::MODE_CONTINUE, false),
            ContextExplanationSnapshot::missing(),
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('requires current workflow mutation authority', $errors[0]);
    }

    public function testExecutionDispatchCannotBeGeneratedForNewTaskEnvelope(): void
    {
        $errors = (new PromptApplicabilityEvaluator())->errors(
            $this->recipe('execution-dispatch', OperatingPromptRecipe::PURPOSE_HANDOFF),
            $this->workflow(WorkflowPromptEnvelope::MODE_START, false),
            null,
        );

        self::assertCount(2, $errors);
        self::assertStringContainsString('requires an existing task', $errors[0]);
        self::assertStringContainsString('requires current workflow mutation authority', $errors[1]);
    }

    public function testWorkflowDisagreementsAreSurfacedAsOwnerBackedBlockers(): void
    {
        $errors = (new PromptApplicabilityEvaluator())->errors(
            $this->recipe('discovery-first', OperatingPromptRecipe::PURPOSE_START),
            $this->workflow(
                WorkflowPromptEnvelope::MODE_CONTINUE,
                false,
                [[
                    'code' => 'run.contract_revision_mismatch',
                    'owner' => 'agent-loop',
                    'message' => 'Run and Contract revisions differ.',
                ]],
            ),
            ContextExplanationSnapshot::missing(),
        );

        self::assertSame(
            ['agent-loop [run.contract_revision_mismatch]: Run and Contract revisions differ.'],
            $errors,
        );
    }

    public function testInvalidRecallContextBlocksCopy(): void
    {
        $errors = (new PromptApplicabilityEvaluator())->errors(
            $this->recipe('discovery-first', OperatingPromptRecipe::PURPOSE_START),
            $this->workflow(WorkflowPromptEnvelope::MODE_CONTINUE, false),
            ContextExplanationSnapshot::invalid(),
        );

        self::assertCount(1, $errors);
        self::assertStringContainsString('could not be verified', $errors[0]);
    }

    public function testStartRecipeRemainsApplicableWithoutMutationAuthority(): void
    {
        self::assertSame([], (new PromptApplicabilityEvaluator())->errors(
            $this->recipe('discovery-first', OperatingPromptRecipe::PURPOSE_START),
            $this->workflow(WorkflowPromptEnvelope::MODE_START, false),
            null,
        ));
    }

    private function recipe(string $id, string $purpose): OperatingPromptRecipe
    {
        return new OperatingPromptRecipe(
            id: $id,
            title: 'Recipe title',
            description: 'Recipe description.',
            level: 1,
            purpose: $purpose,
            arguments: [],
            sourceRef: 'test#' . $id,
            templateSha256: str_repeat('a', 64),
        );
    }

    /**
     * @param WorkflowPromptEnvelope::MODE_* $mode
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function workflow(string $mode, bool $mutationAllowed, array $disagreements = []): WorkflowPromptEnvelope
    {
        return new WorkflowPromptEnvelope(
            mode: $mode,
            taskId: 'TEST-1',
            content: 'Use the governed workflow.',
            mutationAllowed: $mutationAllowed,
            runId: $mode === WorkflowPromptEnvelope::MODE_CONTINUE ? 'run:TEST-1' : null,
            state: $mode === WorkflowPromptEnvelope::MODE_CONTINUE ? 'incomplete' : null,
            nextAction: $mode === WorkflowPromptEnvelope::MODE_CONTINUE ? 'perform host work' : null,
            nextActionKind: $mode === WorkflowPromptEnvelope::MODE_CONTINUE ? 'host_work' : null,
            disagreements: $disagreements,
        );
    }
}
