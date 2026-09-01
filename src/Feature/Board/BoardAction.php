<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Board;

use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class BoardAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(?Request $request = null): Response
    {
        $boardId = $request?->query['board'] ?? null;

        return Response::html($this->templates->render('board/index', ['board' => $this->board->board($boardId)]));
    }
}
