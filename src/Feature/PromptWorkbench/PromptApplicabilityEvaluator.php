<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;

final readonly class PromptApplicabilityEvaluator
{
    /**
     * @return list<string>
     */
    public function errors(
        OperatingPromptRecipe $recipe,
        WorkflowPromptEnvelope $workflow,
        ?ContextExplanationSnapshot $context,
    ): array {
        $errors = [];

        if ($recipe->requiresTaskContext() && $workflow->mode !== WorkflowPromptEnvelope::MODE_CONTINUE) {
            $errors[] = 'Recipe ' . $recipe->id . ' requires an existing task with current owner state.';
        }

        foreach ($workflow->disagreements as $disagreement) {
            $errors[] = sprintf(
                '%s [%s]: %s',
                $disagreement['owner'],
                $disagreement['code'],
                $disagreement['message'],
            );
        }

        if ($recipe->requiresMutationAuthority() && !$workflow->mutationAllowed) {
            $message = 'Recipe ' . $recipe->id . ' requires current workflow mutation authority';
            if ($workflow->state !== null) {
                $message .= '; current state is ' . $workflow->state;
            }
            if ($workflow->nextAction !== null && $workflow->nextAction !== '') {
                $message .= '. Canonical next action: ' . $workflow->nextAction;
            }
            $errors[] = $message . '.';
        }

        if ($context !== null) {
            if ($context->status === ContextExplanationSnapshot::INVALID) {
                $errors[] = $context->problem ?? 'Persisted Recall context is invalid.';
            }

            $explanation = $context->explanation;
            if ($explanation !== null) {
                if (
                    $workflow->recallCompilationId !== null
                    && $explanation->compilationId !== null
                    && !hash_equals($workflow->recallCompilationId, $explanation->compilationId)
                ) {
                    $errors[] = 'Recall context compilation does not match the current workflow envelope.';
                }
                if (
                    $workflow->recallBundleSha256 !== null
                    && !hash_equals($workflow->recallBundleSha256, $explanation->bundleSha256)
                ) {
                    $errors[] = 'Recall context bundle does not match the current workflow envelope.';
                }
            }
        }

        return array_values(array_unique($errors));
    }
}
