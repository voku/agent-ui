<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use JsonException;
use LogicException;
use RuntimeException;
use voku\AgentLoop\Workflow\WorkflowPromptEnvelope;
use voku\AgentRecallCompiler\OperatingPromptPreview;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;

/**
 * UI-owned deterministic composition only. Workflow and recipe semantics stay with their owners.
 */
final readonly class PromptComposer
{
    /**
     * @param array<string, bool|int|string> $arguments
     */
    public function compose(
        WorkflowPromptEnvelope $workflow,
        OperatingPromptRecipe $recipe,
        OperatingPromptPreview $preview,
        array $arguments,
        string $goal = '',
        string $additionalInstruction = '',
        ?CardSnapshot $card = null,
    ): PromptComposition {
        if (!$preview->validation->valid || $preview->content === null || $preview->templateSha256 === null) {
            throw new LogicException('Cannot compose an invalid operating-prompt preview.');
        }
        if ($preview->recipeId !== $recipe->id || !hash_equals($recipe->templateSha256, $preview->templateSha256)) {
            throw new LogicException('Operating-prompt preview provenance does not match the selected recipe.');
        }
        if ($card !== null && $card->id !== $workflow->taskId) {
            throw new LogicException('Board card and workflow prompt envelope refer to different tasks.');
        }

        ksort($arguments, SORT_STRING);
        $goal = self::normalizeText($goal);
        $additionalInstruction = self::normalizeText($additionalInstruction);

        $sections = [$workflow->content];
        if ($goal !== '') {
            $sections[] = "Developer-supplied goal:\n" . $goal;
        }
        if ($card !== null) {
            $sections[] = implode("\n", [
                'Board-owned task context:',
                'Task: ' . $card->id,
                'Title: ' . self::normalizeInline($card->title),
                'Summary: ' . self::normalizeInline($card->summary),
            ]);
        }
        if ($additionalInstruction !== '') {
            $sections[] = "Additional developer instruction:\n" . $additionalInstruction;
        }
        $sections[] = sprintf(
            "Recall-owned operating prompt recipe: %s (L%d)\n%s",
            $recipe->id,
            $recipe->level,
            $preview->content,
        );

        $prompt = implode("\n\n", $sections);
        $payload = [
            'schema_version' => '1.0',
            'workflow_digest' => 'sha256:' . $workflow->digest,
            'recipe_id' => $recipe->id,
            'recipe_template_sha256' => $recipe->templateSha256,
            'arguments' => $arguments,
            'goal' => $goal,
            'additional_instruction' => $additionalInstruction,
            'board' => $card === null ? null : [
                'task_id' => $card->id,
                'title' => self::normalizeText($card->title),
                'summary' => self::normalizeText($card->summary),
            ],
            'prompt' => $prompt,
        ];

        try {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode deterministic prompt composition: ' . $exception->getMessage(), 0, $exception);
        }

        return new PromptComposition(
            prompt: $prompt,
            digest: hash('sha256', $encoded),
            workflow: $workflow,
            recipe: $recipe,
        );
    }

    private static function normalizeText(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    private static function normalizeInline(string $value): string
    {
        return preg_replace('/\s+/u', ' ', self::normalizeText($value)) ?? self::normalizeText($value);
    }
}
