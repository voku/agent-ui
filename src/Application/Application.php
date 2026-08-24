<?php

declare(strict_types=1);

namespace voku\AgentUi\Application;

use InvalidArgumentException;
use Throwable;
use voku\AgentUi\Feature\Board\BoardAction;
use voku\AgentUi\Feature\Evidence\EvidenceAction;
use voku\AgentUi\Feature\Home\HomeAction;
use voku\AgentUi\Feature\Task\TaskAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Http\Router;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class Application
{
    private Router $router;
    private HomeAction $home;
    private BoardAction $board;
    private TaskAction $task;
    private EvidenceAction $evidence;

    public function __construct(string $projectRoot, string $templateRoot)
    {
        $board = new BoardProjectionGateway($projectRoot);
        $workflow = new WorkflowProjectionGateway($projectRoot);
        $templates = new TemplateRenderer($templateRoot);

        $this->router = new Router();
        $this->home = new HomeAction($board, $workflow, $templates);
        $this->board = new BoardAction($board, $templates);
        $this->task = new TaskAction($board, $workflow, $templates);
        $this->evidence = new EvidenceAction($workflow, $templates);
    }

    public function handle(Request $request): Response
    {
        try {
            $route = $this->router->match($request);

            return match ($route['route']) {
                'home' => ($this->home)(),
                'board' => ($this->board)(),
                'task' => ($this->task)($route['task_id'] ?? ''),
                'evidence' => ($this->evidence)($route['task_id'] ?? ''),
            };
        } catch (InvalidArgumentException $exception) {
            return Response::html($this->errorPage($exception->getMessage()), 404);
        } catch (Throwable $exception) {
            return Response::html($this->errorPage($exception->getMessage()), 500);
        }
    }

    private function errorPage(string $message): string
    {
        return '<!doctype html><html><body><h1>Agent UI</h1><p>'
            . TemplateRenderer::escape($message)
            . '</p></body></html>';
    }
}
