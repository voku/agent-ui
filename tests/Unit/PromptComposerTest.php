<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentRecallCompiler\OperatingPromptPreview;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentRecallCompiler\OperatingPromptValidationResult;
use voku\AgentUi\Feature\PromptWorkbench\PromptComposer;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;

final class PromptComposerTest extends TestCase
{
    public function testSameInputsProduceSamePromptAndDigestsRegardlessOfArgumentOrder(): void
    {
        $composer = new PromptComposer();
        $workflow = $this->workflow();
        $recipe = $this->recipe();
        $preview = $this->preview($recipe);

        $first = $composer->compose(
            workflow: $workflow,
            recipe: $recipe,
            preview: $preview,
            arguments: ['zeta' => 2, 'alpha' => 'one'],
            goal: "  ship\r\nthis  ",
            context: ContextExplanationSnapshot::missing(),
        );
        $second = $composer->compose(
            workflow: $workflow,
            recipe: $recipe,
            preview: $preview,
            arguments: ['alpha' => 'one', 'zeta' => 2],
            goal: "ship\nthis",
            context: ContextExplanationSnapshot::missing(),
        );

        self::assertSame($first->prompt, $second->prompt);
        self::assertSame($first->promptDigest, $second->promptDigest);
        self::assertSame($first->compositionDigest, $second->compositionDigest);
        self::assertSame(hash('sha256', $first->prompt), $first->promptDigest);
        self::assertNotSame($first->promptDigest, $first->compositionDigest);
    }

    public function testRecallContextProjectionIsIncludedWithoutCopyingPrivateContext(): void
    {
        $composition = (new PromptComposer())->compose(
            workflow: $this->workflow(),
            recipe: $this->recipe(),
            preview: $this->preview($this->recipe()),
            arguments: [],
            context: ContextExplanationSnapshot::missing(),
        );

        self::assertStringContainsString('Recall-owned context projection:', $composition->prompt);
        self::assertStringContainsString('Status: missing', $composition->prompt);
        self::assertStringNotContainsString('selection-report.json', $composition->prompt);
    }

    public function testRejectsFreeFormInstructionWithoutRecallOptIn(): void
    {
        $recipe = $this->recipe();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not allow additional developer instructions');

        (new PromptComposer())->compose(
            workflow: $this->workflow(),
            recipe: $recipe,
            preview: $this->preview($recipe),
            arguments: [],
            additionalInstruction: 'invent another requirement',
        );
    }

    private function workflow(): WorkflowPromptEnvelope
    {
        return new WorkflowPromptEnvelope(
            mode: WorkflowPromptEnvelope::MODE_START,
            taskId: 'TEST-1',
            content: 'Use the governed workflow.',
            mutationAllowed: false,
            runId: null,
            state: null,
            nextAction: null,
            nextActionKind: null,
        );
    }

    private function recipe(): OperatingPromptRecipe
    {
        return new OperatingPromptRecipe(
            id: 'discovery-first',
            title: 'Discovery first',
            description: 'Re-ground the task.',
            level: 2,
            purpose: OperatingPromptRecipe::PURPOSE_START,
            arguments: [],
            sourceRef: 'test#discovery-first',
            templateSha256: str_repeat('b', 64),
        );
    }

    private function preview(OperatingPromptRecipe $recipe): OperatingPromptPreview
    {
        return new OperatingPromptPreview(
            recipeId: $recipe->id,
            level: $recipe->level,
            content: 'Inspect current repository evidence before editing.',
            templateSha256: $recipe->templateSha256,
            validation: OperatingPromptValidationResult::valid(),
        );
    }
}
