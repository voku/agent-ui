<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;

final readonly class PromptWorkbenchViewModel
{
    /**
     * @param list<OperatingPromptRecipe> $recipes
     * @param array<string, string> $argumentValues
     * @param list<string> $errors
     */
    public function __construct(
        public bool $taskAware,
        public ?string $taskId,
        public ?string $taskTitle,
        public array $recipes,
        public string $selectedRecipeId,
        public array $argumentValues,
        public string $goal,
        public string $additionalInstruction,
        public ?ContextExplanationSnapshot $context,
        public ?PromptComposition $composition,
        public array $errors,
    ) {
    }

    /** @return list<PromptRecipeGroup> */
    public function recipeGroups(): array
    {
        $recipesByPurpose = [];
        foreach ($this->recipes as $recipe) {
            $recipesByPurpose[$recipe->purpose][] = $recipe;
        }
        foreach ($recipesByPurpose as &$recipes) {
            usort($recipes, static fn(OperatingPromptRecipe $left, OperatingPromptRecipe $right): int => [
                $left->title,
                $left->id,
            ] <=> [
                $right->title,
                $right->id,
            ]);
        }
        unset($recipes);

        $purposeOrder = [
            OperatingPromptRecipe::PURPOSE_START,
            OperatingPromptRecipe::PURPOSE_PLAN,
            OperatingPromptRecipe::PURPOSE_EXECUTE,
            OperatingPromptRecipe::PURPOSE_RECOVER,
            OperatingPromptRecipe::PURPOSE_REVIEW,
            OperatingPromptRecipe::PURPOSE_SIMPLIFY,
            OperatingPromptRecipe::PURPOSE_HANDOFF,
            OperatingPromptRecipe::PURPOSE_REPORT,
            OperatingPromptRecipe::PURPOSE_UNSPECIFIED,
        ];
        if ($this->taskAware) {
            $purposeOrder = array_values(array_unique([
                OperatingPromptRecipe::PURPOSE_RECOVER,
                ...$purposeOrder,
            ]));
        }

        $groups = [];
        foreach ($purposeOrder as $purpose) {
            $recipes = $recipesByPurpose[$purpose] ?? [];
            if ($recipes === []) {
                continue;
            }
            $groups[] = new PromptRecipeGroup(
                purpose: $purpose,
                title: $this->purposeTitle($purpose),
                recipes: $recipes,
            );
            unset($recipesByPurpose[$purpose]);
        }

        ksort($recipesByPurpose, SORT_STRING);
        foreach ($recipesByPurpose as $purpose => $recipes) {
            $groups[] = new PromptRecipeGroup(
                purpose: $purpose,
                title: ucfirst(str_replace(['-', '_'], ' ', $purpose)),
                recipes: $recipes,
            );
        }

        return $groups;
    }

    private function purposeTitle(string $purpose): string
    {
        if ($this->taskAware && $purpose === OperatingPromptRecipe::PURPOSE_RECOVER) {
            return 'Need help moving forward?';
        }

        return match ($purpose) {
            OperatingPromptRecipe::PURPOSE_START => 'Start',
            OperatingPromptRecipe::PURPOSE_PLAN => 'Plan',
            OperatingPromptRecipe::PURPOSE_EXECUTE => 'Execute',
            OperatingPromptRecipe::PURPOSE_RECOVER => 'Recover',
            OperatingPromptRecipe::PURPOSE_REVIEW => 'Review',
            OperatingPromptRecipe::PURPOSE_SIMPLIFY => 'Simplify',
            OperatingPromptRecipe::PURPOSE_HANDOFF => 'Handoff',
            OperatingPromptRecipe::PURPOSE_REPORT => 'Report',
            default => 'Other',
        };
    }
}
