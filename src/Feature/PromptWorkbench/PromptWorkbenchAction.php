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
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\OperatingPromptCatalogGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class PromptWorkbenchAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowPromptGateway $workflow,
        private OperatingPromptCatalogGateway $catalog,
        private ContextExplanationGateway $context,
        private PromptApplicabilityEvaluator $applicability,
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
        $taskId = $card->id ?? strtoupper(trim($request->body['task_id'] ?? ''));
        $goal = trim($request->body['goal'] ?? '');
        $additionalInstruction = trim($request->body['additional_instruction'] ?? '');
        $selectedRecipeId = trim($request->body['recipe'] ?? '');
        $argumentValues = $this->argumentValues($request->body);
        $context = $card === null ? null : $this->context->task($card->id);
        $errors = [];
        $composition = null;

        if ($request->method === 'POST') {
            $action = $request->body['action'] ?? '';
            if ($action === 'select') {
                if ($selectedRecipeId === '') {
                    $errors[] = 'Choose an operating-prompt recipe explicitly.';
                } else {
                    try {
                        $this->catalog->recipe($selectedRecipeId);
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = $exception->getMessage();
                    }
                }
            } elseif ($action === 'generate') {
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
                        if ($additionalInstruction !== '' && !$recipe->allowsAdditionalInstruction()) {
                            $errors[] = 'Selected recipe does not allow additional developer instructions.';
                        }

                        $arguments = $this->normalizeArguments($request->body, $recipe);
                        $preview = $this->catalog->preview(new OperatingPromptRequest($recipe->id, $arguments));
                        if (!$preview->validation->valid) {
                            $errors = array_merge($errors, $preview->validation->errors);
                        }

                        if ($errors === []) {
                            $envelope = $taskAware
                                ? $this->workflow->continue($taskId)
                                : $this->workflow->start($taskId);
                            $errors = $this->applicability->errors($recipe, $envelope, $context);

                            if ($errors === []) {
                                $composition = $this->composer->compose(
                                    workflow: $envelope,
                                    recipe: $recipe,
                                    preview: $preview,
                                    arguments: $arguments,
                                    goal: $goal,
                                    additionalInstruction: $additionalInstruction,
                                    card: $card,
                                    context: $context,
                                );
                            }
                        }
                    } catch (InvalidArgumentException $exception) {
                        $errors[] = $exception->getMessage();
                    }
                }
            } else {
                $errors[] = 'Unknown prompt workbench action.';
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
                context: $context,
                composition: $composition,
                errors: array_values(array_unique($errors)),
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

            $arguments[$name] = $this->normalizeArgumentValue($rawValue, $argument);
        }
        ksort($arguments, SORT_STRING);

        return $arguments;
    }

    private function normalizeArgumentValue(string $rawValue, OperatingPromptArgument $argument): bool|int|string
    {
        if ($argument->type === OperatingPromptArgument::TYPE_BOOLEAN) {
            return match ($rawValue) {
                'true' => true,
                'false' => false,
                default => $rawValue,
            };
        }
        if ($argument->type === OperatingPromptArgument::TYPE_INTEGER) {
            $integer = filter_var($rawValue, FILTER_VALIDATE_INT);

            return is_int($integer) ? $integer : $rawValue;
        }

        return $rawValue;
    }
}
