<?php

declare(strict_types=1);

namespace voku\AgentUi\Application;

use InvalidArgumentException;
use Throwable;
use voku\AgentUi\Feature\Board\BoardAction;
use voku\AgentUi\Feature\Context\ContextAction;
use voku\AgentUi\Feature\Evidence\EvidenceAction;
use voku\AgentUi\Feature\History\HistoryAction;
use voku\AgentUi\Feature\Home\HomeAction;
use voku\AgentUi\Feature\HumanDecision\HumanDecisionAction;
use voku\AgentUi\Feature\Knowledge\KnowledgeAction;
use voku\AgentUi\Feature\Map\MapAction;
use voku\AgentUi\Feature\PromptWorkbench\PromptApplicabilityEvaluator;
use voku\AgentUi\Feature\PromptWorkbench\PromptComposer;
use voku\AgentUi\Feature\PromptWorkbench\PromptWorkbenchAction;
use voku\AgentUi\Feature\Runner\RunnerAction;
use voku\AgentUi\Feature\Setup\SetupAction;
use voku\AgentUi\Feature\Task\TaskAction;
use voku\AgentUi\Feature\Work\WorkAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Http\Router;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardMutationGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\RepositorySetupGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowPromptGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\OperatingPromptCatalogGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class Application
{
    private Router $router;
    private HomeAction $home;
    private SetupAction $setup;
    private BoardAction $board;
    private KnowledgeAction $knowledge;
    private MapAction $map;
    private TaskAction $task;
    private PromptWorkbenchAction $prompts;
    private ContextAction $context;
    private WorkAction $work;
    private EvidenceAction $evidence;
    private HistoryAction $history;
    private HumanDecisionAction $humanDecision;
    private RunnerAction $runner;

    private TemplateRenderer $templates;

    public function __construct(string $projectRoot, string $templateRoot)
    {
        $board = new BoardProjectionGateway($projectRoot);
        $workflow = new WorkflowProjectionGateway($projectRoot);
        $workflowPrompt = new WorkflowPromptGateway($projectRoot);
        $promptCatalog = new OperatingPromptCatalogGateway();
        $audit = new AuditTrailGateway($projectRoot);
        $decisions = new HumanDecisionGateway($projectRoot);
        $runner = new RunnerGateway($projectRoot);
        $context = new ContextExplanationGateway($projectRoot);
        $transparency = new TaskTransparencyGateway($projectRoot);
        $learning = new LearningCatalogGateway($projectRoot);
        $setup = new RepositorySetupGateway($projectRoot);
        $mutation = new CardMutationGateway($projectRoot);
        $map = new MapProjectionGateway($projectRoot);
        $csrf = new CsrfTokenManager();
        $templates = new TemplateRenderer($templateRoot);
        $this->templates = $templates;

        $this->router = new Router();
        $this->home = new HomeAction($board, $workflow, $setup, $learning, $templates);
        $this->setup = new SetupAction($setup, $csrf, $templates);
        $this->board = new BoardAction($board, $mutation, $csrf, $templates);
        $this->knowledge = new KnowledgeAction($learning, $templates);
        $this->map = new MapAction($map, $templates);
        $this->task = new TaskAction(
            $board,
            $workflow,
            $decisions,
            $runner,
            $context,
            $transparency,
            $mutation,
            $csrf,
            $templates,
        );
        $this->prompts = new PromptWorkbenchAction(
            $board,
            $workflowPrompt,
            $promptCatalog,
            $context,
            new PromptApplicabilityEvaluator(),
            new PromptComposer(),
            $templates,
        );
        $this->context = new ContextAction($board, $context, $transparency, $templates);
        $this->work = new WorkAction($board, $transparency, $templates);
        $this->evidence = new EvidenceAction($workflow, $audit, $templates);
        $this->history = new HistoryAction($audit, $templates);
        $this->humanDecision = new HumanDecisionAction($decisions, $csrf);
        $this->runner = new RunnerAction($runner, $csrf);
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->match($request);

            return match ($route['route']) {
                'home' => ($this->home)(),
                'setup' => $this->setup->overview(),
                'prompts' => $this->prompts->newTask($request),
                'board' => ($this->board)($request),
                'board_new' => $this->board->newCard($request),
                'board_create' => $this->board->createCard($request),
                'map' => $this->map->index($request),
                'map_symbol' => $this->map->symbol($request),
                'map_context' => $this->map->context($request),
                'knowledge' => $this->knowledge->overview($request),
                'knowledge_finding' => $this->knowledge->finding($route['knowledge_id'] ?? ''),
                'knowledge_proposal' => $this->knowledge->proposal($route['knowledge_id'] ?? ''),
                'knowledge_guidance' => $this->knowledge->guidance($route['knowledge_id'] ?? ''),
                'task' => ($this->task)($route['task_id'] ?? ''),
                'task_edit' => $this->task->edit($route['task_id'] ?? ''),
                'task_update' => $this->task->update($route['task_id'] ?? '', $request),
                'task_move' => $this->task->move($route['task_id'] ?? '', $request),
                'task_claim' => $this->task->claim($route['task_id'] ?? '', $request),
                'task_release' => $this->task->release($route['task_id'] ?? '', $request),
                'task_contract' => $this->task->contractView($route['task_id'] ?? ''),
                'task_contract_propose' => $this->task->contractPropose($route['task_id'] ?? '', $request),
                'task_prompts' => $this->prompts->task($route['task_id'] ?? '', $request),
                'task_learning' => $this->knowledge->task($route['task_id'] ?? ''),
                'context' => ($this->context)($route['task_id'] ?? ''),
                'work' => ($this->work)($route['task_id'] ?? ''),
                'evidence' => ($this->evidence)($route['task_id'] ?? ''),
                'history' => ($this->history)($route['task_id'] ?? ''),
                'handoff' => Response::redirect('/task/' . rawurlencode($route['task_id'] ?? '') . '/prompts'),
                'approve', 'review_ack', 'learning' => ($this->humanDecision)(
                    $route['task_id'] ?? '',
                    $route['route'],
                    $request,
                ),
                'runner_run', 'runner_resume', 'runner_cancel' => ($this->runner)(
                    $route['task_id'] ?? '',
                    $route['route'],
                    $request,
                ),
                'setup_install', 'setup_remove', 'setup_sync_policy', 'setup_sync_git' => $this->setup->mutate(
                    $route['route'],
                    $route['agent'] ?? '',
                    $request,
                ),
            };
        } catch (InvalidArgumentException $exception) {
            return $request->method === 'POST'
                ? $this->errorPage(400, 'Request rejected', $exception->getMessage())
                : $this->errorPage(404, 'Page not found', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('agent-ui request failed: ' . $exception->getMessage());

            return $this->errorPage(500, 'Something went wrong', 'Operation failed. Inspect the local server log for details.');
        }
    }

    /**
     * Errors are pages of this control plane, not a way out of it.
     *
     * A mistyped URL, an expired CSRF token and an owner failure used to drop
     * the operator onto an unstyled stub with no navigation, so the fastest
     * route back into the app was the browser's back button.
     */
    private function errorPage(int $status, string $heading, string $message): Response
    {
        try {
            return Response::html($this->templates->render('error/index', [
                'status' => $status,
                'heading' => $heading,
                'message' => $message,
            ]), $status);
        } catch (Throwable) {
            // The layout itself is unavailable; say so rather than rendering nothing.
            return Response::html(
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>agent-ui</title></head>'
                . '<body><h1>' . TemplateRenderer::escape($heading) . '</h1><p>'
                . TemplateRenderer::escape($message)
                . '</p><p><a href="/">Overview</a></p></body></html>',
                $status,
            );
        }
    }
}
