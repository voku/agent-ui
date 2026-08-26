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
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\RepositorySetupGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowPromptGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
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
    private TaskAction $task;
    private PromptWorkbenchAction $prompts;
    private ContextAction $context;
    private WorkAction $work;
    private EvidenceAction $evidence;
    private HistoryAction $history;
    private HumanDecisionAction $humanDecision;
    private RunnerAction $runner;

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
        $csrf = new CsrfTokenManager();
        $templates = new TemplateRenderer($templateRoot);

        $this->router = new Router();
        $this->home = new HomeAction($board, $workflow, $setup, $learning, $templates);
        $this->setup = new SetupAction($setup, $csrf, $templates);
        $this->board = new BoardAction($board, $templates);
        $this->knowledge = new KnowledgeAction($learning, $templates);
        $this->task = new TaskAction(
            $board,
            $workflow,
            $decisions,
            $runner,
            $context,
            $transparency,
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
                'board' => ($this->board)(),
                'knowledge' => $this->knowledge->overview(),
                'knowledge_finding' => $this->knowledge->finding($route['knowledge_id'] ?? ''),
                'knowledge_proposal' => $this->knowledge->proposal($route['knowledge_id'] ?? ''),
                'knowledge_guidance' => $this->knowledge->guidance($route['knowledge_id'] ?? ''),
                'task' => ($this->task)($route['task_id'] ?? ''),
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
            return Response::html($this->errorPage($exception->getMessage()), $request->method === 'POST' ? 400 : 404);
        } catch (Throwable $exception) {
            error_log('agent-ui request failed: ' . $exception->getMessage());

            return Response::html($this->errorPage('Operation failed. Inspect the local server log for details.'), 500);
        }
    }

    private function errorPage(string $message): string
    {
        return '<!doctype html><html><body><h1>Agent UI</h1><p>'
            . TemplateRenderer::escape($message)
            . '</p></body></html>';
    }
}
