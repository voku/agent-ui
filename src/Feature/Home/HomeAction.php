<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Home;

use Throwable;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\RepositorySetupGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class HomeAction
{
    public const MAXIMUM_CURRENT_WORK = 10;

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
        $totalWorkCount = 0;

        $snapshots = $this->workflow->tasks(array_map(static fn(CardSnapshot $card): string => $card->id, $board->cards));
        foreach ($board->cards as $card) {
            $snapshot = $snapshots[$card->id] ?? null;
            if ($snapshot === null || $snapshot->state === 'complete' || $snapshot->nextActionKind === 'none') {
                continue;
            }

            if ($snapshot->nextActionKind === 'decision_required') {
                $attention[] = $snapshot;
            }

            $totalWorkCount++;
            if (count($work) < self::MAXIMUM_CURRENT_WORK) {
                $work[] = $snapshot;
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
        try {
            $mapReadiness = $this->map->readiness();
        } catch (Throwable) {
            // map remains defensive
        }

        $runnerInstalled = $this->runner->isInstalled();
        $activeRunnerTasks = [];
        if ($runnerInstalled) {
            foreach ($board->cards as $card) {
                try {
                    // Read-only journal observation: a GET must never ask Runner
                    // for authority, which may prepare Loop execution artifacts.
                    $runnerSnapshot = $this->runner->observe($card->id);
                    if ($runnerSnapshot->processRunning()) {
                        $activeRunnerTasks[] = [
                            'taskId' => $card->id,
                            'title' => $card->title,
                            'stage' => $runnerSnapshot->observationStageId,
                        ];
                    }
                } catch (Throwable) {
                    // runner status probe stays safe
                }
            }
        }

        return Response::html($this->templates->render('home/index', [
            'board' => $board,
            'attention' => $attention,
            'work' => $work,
            'total_work_count' => $totalWorkCount,
            'setup' => $setup,
            'setup_error' => $setupError,
            'learning' => $learning,
            'learning_error' => $learningError,
            'map_readiness' => $mapReadiness,
            'runner_installed' => $runnerInstalled,
            'active_runner_tasks' => $activeRunnerTasks,
        ]));
    }
}
