<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Board;

use InvalidArgumentException;
use voku\AgentUi\Http\FlashNotice;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardMutationGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class BoardAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private CardMutationGateway $mutation,
        private CsrfTokenManager $csrf,
        private TemplateRenderer $templates,
        private FlashNotice $notice = new FlashNotice(),
    ) {
    }

    public function __invoke(?Request $request = null): Response
    {
        $boardId = $request?->query['board'] ?? null;

        return Response::html($this->templates->render('board/index', [
            'board' => $this->board->board($boardId),
            'csrf_token' => $this->csrf->token(),
        ]));
    }

    public function newCard(Request $request): Response
    {
        $boardId = $request->query['board'] ?? null;
        $board = $this->board->board($boardId);
        $suggestedId = $this->mutation->suggestNextId($boardId);
        $lanes = $this->mutation->availableLanes($boardId);

        return Response::html($this->templates->render('board/new', [
            'board' => $board,
            'suggested_id' => $suggestedId,
            'lanes' => $lanes,
            'csrf_token' => $this->csrf->token(),
        ]));
    }

    public function createCard(Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        $cardId = $this->required($request, 'card_id', 50);
        $title = $this->required($request, 'title', 500);
        $lane = $this->required($request, 'lane', 50);
        $status = $this->optionalString($request, 'status') ?? '';
        $summary = $this->optionalString($request, 'summary') ?? '';
        $taskBrief = $this->optionalString($request, 'task_brief') ?? '';
        $nextAction = $this->optionalString($request, 'next_action') ?? '';
        $validation = $this->optionalString($request, 'validation') ?? '';
        $boardId = $this->optionalString($request, 'board_id');

        $priority = null;
        if (isset($request->body['priority']) && $request->body['priority'] !== '') {
            $p = (int) $request->body['priority'];
            if ($p >= 1 && $p <= 5) {
                $priority = $p;
            }
        }
        $assignee = $this->optionalString($request, 'assignee');

        $snapshot = $this->mutation->create(
            cardId: $cardId,
            title: $title,
            lane: $lane,
            status: $status,
            summary: $summary,
            taskBrief: $taskBrief,
            nextAction: $nextAction,
            validation: $validation,
            priority: $priority,
            assignee: $assignee,
            boardId: $boardId,
        );

        $this->notice->record(sprintf('Card %s created successfully.', $snapshot->id));

        return Response::redirect('/task/' . rawurlencode($snapshot->id));
    }

    private function required(Request $request, string $key, int $maxLength): string
    {
        $value = trim($request->body[$key] ?? '');
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty value of at most %d bytes.', $key, $maxLength));
        }

        return $value;
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = trim($request->body[$key] ?? '');
        if ($value === '') {
            return null;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s contains invalid characters.', $key));
        }

        return $value;
    }
}
