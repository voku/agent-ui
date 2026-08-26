<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use voku\AgentRecallCompiler\OperatingPromptRecipe;

final readonly class PromptRecipeGroup
{
    /** @param list<OperatingPromptRecipe> $recipes */
    public function __construct(
        public string $purpose,
        public string $title,
        public array $recipes,
    ) {
    }
}
