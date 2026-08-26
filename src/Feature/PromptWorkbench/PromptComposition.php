<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;

final readonly class PromptComposition
{
    public function __construct(
        public string $prompt,
        public string $promptDigest,
        public string $compositionDigest,
        public WorkflowPromptEnvelope $workflow,
        public OperatingPromptRecipe $recipe,
        public ?ContextExplanationSnapshot $context,
    ) {
    }
}
