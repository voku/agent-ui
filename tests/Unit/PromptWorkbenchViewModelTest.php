<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\OperatingPromptArgument;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Feature\PromptWorkbench\PromptWorkbenchViewModel;

final class PromptWorkbenchViewModelTest extends TestCase
{
    /** Prove task-aware presentation elevates owner-classified recovery without recipe-id policy. */
    public function testTaskAwareGroupsPutRecoverFirstAndSortRecipesDeterministically(): void
    {
        $view = $this->view(true, [
            $this->recipe('review-anything', 'Zulu review', OperatingPromptRecipe::PURPOSE_REVIEW),
            $this->recipe('arbitrary-recovery-z', 'Resume work', OperatingPromptRecipe::PURPOSE_RECOVER),
            $this->recipe('start-anything', 'Inspect current state', OperatingPromptRecipe::PURPOSE_START),
            $this->recipe('arbitrary-recovery-a', 'Bound repeated failures', OperatingPromptRecipe::PURPOSE_RECOVER),
        ]);

        $groups = $view->recipeGroups();

        self::assertSame([
            OperatingPromptRecipe::PURPOSE_RECOVER,
            OperatingPromptRecipe::PURPOSE_START,
            OperatingPromptRecipe::PURPOSE_REVIEW,
        ], array_map(static fn ($group): string => $group->purpose, $groups));
        self::assertSame('Need help moving forward?', $groups[0]->title);
        self::assertSame(
            ['arbitrary-recovery-a', 'arbitrary-recovery-z'],
            array_map(static fn (OperatingPromptRecipe $recipe): string => $recipe->id, $groups[0]->recipes),
        );
        self::assertSame('', $view->selectedRecipeId);
    }

    /** Prove new-task grouping follows purpose semantics and does not elevate recovery implicitly. */
    public function testNewTaskGroupsUseStablePurposeOrder(): void
    {
        $view = $this->view(false, [
            $this->recipe('recover-x', 'Recover X', OperatingPromptRecipe::PURPOSE_RECOVER),
            $this->recipe('execute-x', 'Execute X', OperatingPromptRecipe::PURPOSE_EXECUTE),
            $this->recipe('plan-x', 'Plan X', OperatingPromptRecipe::PURPOSE_PLAN),
            $this->recipe('start-x', 'Start X', OperatingPromptRecipe::PURPOSE_START),
        ]);

        self::assertSame([
            OperatingPromptRecipe::PURPOSE_START,
            OperatingPromptRecipe::PURPOSE_PLAN,
            OperatingPromptRecipe::PURPOSE_EXECUTE,
            OperatingPromptRecipe::PURPOSE_RECOVER,
        ], array_map(static fn ($group): string => $group->purpose, $view->recipeGroups()));
    }

    /** Prove required developer input stays owner-declared on the recipe projection. */
    public function testGroupedRecipePreservesOwnerDeclaredArgumentsAndAuthority(): void
    {
        $argument = new OperatingPromptArgument(
            name: 'done_condition',
            type: OperatingPromptArgument::TYPE_STRING,
            required: true,
            description: 'Observable stop condition.',
            minimum: null,
            maximum: null,
            examples: ['tests pass'],
        );
        $recipe = new OperatingPromptRecipe(
            id: 'recover-with-input',
            title: 'Continue carefully',
            description: 'Continue from current evidence.',
            level: 1,
            purpose: OperatingPromptRecipe::PURPOSE_RECOVER,
            arguments: [$argument],
            sourceRef: 'test#recover-with-input',
            templateSha256: str_repeat('a', 64),
        );

        $grouped = $this->view(true, [$recipe])->recipeGroups()[0]->recipes[0];

        self::assertSame([$argument], $grouped->arguments);
        self::assertFalse($grouped->requiresMutationAuthority());
    }

    /** @param list<OperatingPromptRecipe> $recipes */
    private function view(bool $taskAware, array $recipes): PromptWorkbenchViewModel
    {
        return new PromptWorkbenchViewModel(
            taskAware: $taskAware,
            taskId: $taskAware ? 'TEST-1' : null,
            taskTitle: null,
            recipes: $recipes,
            selectedRecipeId: '',
            argumentValues: [],
            goal: '',
            additionalInstruction: '',
            context: null,
            composition: null,
            errors: [],
        );
    }

    /** @param OperatingPromptRecipe::PURPOSE_* $purpose */
    private function recipe(string $id, string $title, string $purpose): OperatingPromptRecipe
    {
        return new OperatingPromptRecipe(
            id: $id,
            title: $title,
            description: 'Recipe description.',
            level: 1,
            purpose: $purpose,
            arguments: [],
            sourceRef: 'test#' . $id,
            templateSha256: str_repeat('b', 64),
        );
    }
}
