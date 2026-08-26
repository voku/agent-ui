<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use voku\AgentRecallCompiler\OperatingPromptRecipe;

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
        public ?PromptComposition $composition,
        public array $errors,
    ) {
    }
}
