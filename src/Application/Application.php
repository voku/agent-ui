<?php

declare(strict_types=1);

namespace voku\AgentUi\Application;

use InvalidArgumentException;
use Throwable;
use voku\AgentUi\Feature\Board\BoardAction;
use voku\AgentUi\Feature\Context\ContextAction;
use voku\AgentUi\Feature\Evidence\EvidenceAction;
use voku\AgentUi\Feature\Handoff\GuidedHandoffAction;
use voku\AgentUi\Feature\Handoff\GuidedHandoffBuilder;
use voku\AgentUi\Feature\History\HistoryAction;
use voku\AgentUi\Feature\Home\HomeAction;
use voku\AgentUi\Feature\HumanDecision\HumanDecisionAction;
use voku\AgentUi\Feature\Runner\RunnerAction;
use voku\AgentUi\Feature\Task\TaskAction;
use voku\AgentUi\Feature\Work\WorkAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Http\Router;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class Application
{
    private Router $router;
    private HomeAction $home;
    private BoardAction $board;
    private TaskAction $task;
    private ContextAction $context;
    private WorkAction $work;
    private EvidenceAction $evidence;
    private HistoryAction $history;
    private GuidedHandoffAction $handoff;
    private HumanDecisionAction $humanDecision;
    private RunnerAction $runner;

    public function __construct(string $projectRoot, string $templateRoot)
    {
        $board = new BoardProjectionGateway($projectRoot);
        $workflow = new WorkflowProjectionGateway($projectRoot);
        $audit = new AuditTrailGateway($projectRoot);
        $decisions = new HumanDecisionGateway($projectRoot);
        $runner = new RunnerGateway($projectRoot);
        $context = new ContextExplanationGateway($projectRoot);
        $transparency = new TaskTransparencyGateway($projectRoot);
        $csrf = new CsrfTokenManager();
        $templates = new TemplateRenderer($templateRoot);

        $this->router = new Router();
        $this->home = new HomeAction($board, $workflow, $templates);
        $this->board = new BoardAction($board, $templates);
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
        $this->context = new ContextAction($board, $context, $transparency, $templates);
        $this->work = new WorkAction($board, $transparency, $templates);
        $this->evidence = new EvidenceAction($workflow, $audit, $templates);
        $this->history = new HistoryAction($audit, $templates);
        $this->handoff = new GuidedHandoffAction($board, $workflow, new GuidedHandoffBuilder(), $templates);
        $this->humanDecision = new HumanDecisionAction($decisions, $csrf);
        $this->runner = new RunnerAction($runner, $csrf);
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->match($request);

            return match ($route['route']) {
                'home' => ($this->home)(),
                'board' => ($this->board)(),
                'task' => ($this->task)($route['task_id'] ?? ''),
                'context' => ($this->context)($route['task_id'] ?? ''),
                'work' => ($this->work)($route['task_id'] ?? ''),
                'evidence' => ($this->evidence)($route['task_id'] ?? ''),
                'history' => ($this->history)($route['task_id'] ?? ''),
                'handoff' => ($this->handoff)($route['task_id'] ?? ''),
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
