<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Home;

use Throwable;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\RepositorySetupGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class HomeAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
        private RepositorySetupGateway $setup,
        private LearningCatalogGateway $learning,
        private MapProjectionGateway $map,
        private RunnerGateway $runner,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(): Response
    {
        $board = $this->board->board();
        $attention = [];
        $work = [];

        foreach ($board->cards as $card) {
            try {
                $snapshot = $this->workflow->task($card->id);
            } catch (Throwable) {
                continue;
            }

            $work[] = $snapshot;
            if ($snapshot->nextActionKind === 'decision_required') {
                $attention[] = $snapshot;
            }
        }

        $setup = null;
        $setupError = null;
        try {
            $setup = $this->setup->overview();
        } catch (Throwable $exception) {
            $setupError = $exception->getMessage();
        }

        $learning = null;
        $learningError = null;
        try {
            $learning = $this->learning->overview();
        } catch (Throwable $exception) {
            $learningError = $exception->getMessage();
        }

        $mapReadiness = null;
        $graph = null;
        try {
            $mapReadiness = $this->map->readiness();
            if ($mapReadiness->isUsable()) {
                $graph = $this->map->graph();
            }
        } catch (Throwable) {
            // map remains defensive
        }

        $runnerInstalled = $this->runner->isInstalled();
        $activeRunnerTasks = [];
        if ($runnerInstalled) {
            foreach ($board->cards as $card) {
                try {
                    $runnerSnapshot = $this->runner->status($card->id);
                    if ($runnerSnapshot->observationStatus === 'running') {
                        $activeRunnerTasks[] = [
                            'taskId' => $card->id,
                            'title' => $card->title,
                            'stage' => $runnerSnapshot->observationStageId ?? $runnerSnapshot->currentStageId,
                        ];
                    }
                } catch (Throwable) {
                    // runner status probe stays safe
                }
            }
        }

        $laneCounts = [];
        foreach ($board->lanes as $lane) {
            $laneCounts[$lane] = 0;
        }
        foreach ($board->cards as $card) {
            $laneCounts[$card->lane] = ($laneCounts[$card->lane] ?? 0) + 1;
        }

        return Response::html($this->templates->render('home/index', [
            'board' => $board,
            'attention' => $attention,
            'work' => $work,
            'setup' => $setup,
            'setup_error' => $setupError,
            'learning' => $learning,
            'learning_error' => $learningError,
            'map_readiness' => $mapReadiness,
            'graph' => $graph,
            'runner_installed' => $runnerInstalled,
            'active_runner_tasks' => $activeRunnerTasks,
            'lane_counts' => $laneCounts,
        ]));
    }
}
