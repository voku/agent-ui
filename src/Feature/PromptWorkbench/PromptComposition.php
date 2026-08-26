<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentRecallCompiler\OperatingPromptRecipe;

final readonly class PromptComposition
{
    public function __construct(
        public string $prompt,
        public string $digest,
        public WorkflowPromptEnvelope $workflow,
        public OperatingPromptRecipe $recipe,
    ) {
    }
}
