<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Home;

use Throwable;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\RepositorySetupGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class HomeAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
        private RepositorySetupGateway $setup,
        private LearningCatalogGateway $learning,
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

        return Response::html($this->templates->render('home/index', [
            'board' => $board,
            'attention' => $attention,
            'work' => $work,
            'setup' => $setup,
            'setup_error' => $setupError,
            'learning' => $learning,
            'learning_error' => $learningError,
        ]));
    }
}
