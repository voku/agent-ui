<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\PromptWorkbench;

use InvalidArgumentException;
use voku\AgentRecallCompiler\OperatingPromptArgument;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentRecallCompiler\OperatingPromptRequest;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowPromptGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\OperatingPromptCatalogGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class PromptWorkbenchAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowPromptGateway $workflow,
        private OperatingPromptCatalogGateway $catalog,
        private PromptComposer $composer,
        private TemplateRenderer $templates,
    ) {
    }

    public function newTask(Request $request): Response
    {
        return $this->render(null, $request);
    }

    public function task(string $taskId, Request $request): Response
    {
        return $this->render($this->board->card($taskId), $request);
    }

    private function render(?CardSnapshot $card, Request $request): Response
    {
        $recipes = $this->catalog->recipes();
        $taskAware = $card !== null;
        $taskId = $card?->id ?? strtoupper(trim($request->body['task_id'] ?? ''));
        $goal = trim($request->body['goal'] ?? '');
        $additionalInstruction = trim($request->body['additional_instruction'] ?? '');
        $selectedRecipeId = trim($request->body['recipe'] ?? '');
        $argumentValues = $this->argumentValues($request->body);
        $errors = [];
        $composition = null;

        if ($request->method === 'POST') {
            if ($taskId === '') {
                $errors[] = 'Task ID is required.';
            }
            if (!$taskAware && $goal === '') {
                $errors[] = 'Goal is required for a new task prompt.';
            }
            if ($selectedRecipeId === '') {
                $errors[] = 'Choose an operating-prompt recipe explicitly.';
            }

            if ($errors === []) {
                try {
                    $recipe = $this->catalog->recipe($selectedRecipeId);
                    $arguments = $this->normalizeArguments($request->body, $recipe);
                    $preview = $this->catalog->preview(new OperatingPromptRequest($recipe->id, $arguments));
                    if (!$preview->validation->valid) {
                        $errors = $preview->validation->errors;
                    } else {
                        $envelope = $taskAware
                            ? $this->workflow->continue($taskId)
                            : $this->workflow->start($taskId);
                        $composition = $this->composer->compose(
                            workflow: $envelope,
                            recipe: $recipe,
                            preview: $preview,
                            arguments: $arguments,
                            goal: $goal,
                            additionalInstruction: $additionalInstruction,
                            card: $card,
                        );
                    }
                } catch (InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        return Response::html($this->templates->render('prompts/index', [
            'workbench' => new PromptWorkbenchViewModel(
                taskAware: $taskAware,
                taskId: $taskId === '' ? null : $taskId,
                taskTitle: $card?->title,
                recipes: $recipes,
                selectedRecipeId: $selectedRecipeId,
                argumentValues: $argumentValues,
                goal: $goal,
                additionalInstruction: $additionalInstruction,
                composition: $composition,
                errors: $errors,
            ),
        ]), $errors === [] ? 200 : 400);
    }

    /**
     * @param array<string, string> $body
     * @return array<string, string>
     */
    private function argumentValues(array $body): array
    {
        $values = [];
        foreach ($body as $name => $value) {
            if (!str_starts_with($name, 'arg_')) {
                continue;
            }
            $argumentName = substr($name, 4);
            if ($argumentName !== '') {
                $values[$argumentName] = $value;
            }
        }
        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param array<string, string> $body
     * @return array<string, bool|int|string>
     */
    private function normalizeArguments(array $body, OperatingPromptRecipe $recipe): array
    {
        $known = [];
        foreach ($recipe->arguments as $argument) {
            $known[$argument->name] = $argument;
        }

        $arguments = [];
        foreach ($this->argumentValues($body) as $name => $rawValue) {
            $rawValue = trim($rawValue);
            if ($rawValue === '') {
                continue;
            }

            $argument = $known[$name] ?? null;
            if ($argument === null) {
                $arguments[$name] = $rawValue;
                continue;
            }

            $arguments[$name] = match ($argument->type) {
                OperatingPromptArgument::TYPE_BOOLEAN => match ($rawValue) {
                    'true' => true,
                    'false' => false,
                    default => $rawValue,
                },
                OperatingPromptArgument::TYPE_INTEGER => preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $rawValue) === 1
                    ? (int) $rawValue
                    : $rawValue,
                OperatingPromptArgument::TYPE_SCALAR, OperatingPromptArgument::TYPE_STRING => $rawValue,
            };
        }
        ksort($arguments, SORT_STRING);

        return $arguments;
    }
}
