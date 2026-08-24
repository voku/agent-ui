<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Home;

use Throwable;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class HomeAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(): Response
    {
        $board = $this->board->board();
        $attention = [];

        foreach ($board->cards as $card) {
            try {
                $snapshot = $this->workflow->task($card->id);
            } catch (Throwable) {
                continue;
            }

            if ($snapshot->nextActionKind === 'decision_required') {
                $attention[] = $snapshot;
            }
        }

        return Response::html($this->templates->render('home/index', [
            'board' => $board,
            'attention' => $attention,
        ]));
    }
}
