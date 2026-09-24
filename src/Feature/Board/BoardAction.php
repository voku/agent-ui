<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Board;

use InvalidArgumentException;
use Throwable;
use voku\AgentUi\Http\FlashNotice;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardMutationGateway;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class BoardAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private CardMutationGateway $mutation,
        private WorkflowProjectionGateway $workflow,
        private CsrfTokenManager $csrf,
        private TemplateRenderer $templates,
        private FlashNotice $notice = new FlashNotice(),
    ) {
    }

    /** Filter value for cards whose board and workflow owners disagree. */
    public const string FILTER_DISAGREEMENT = 'disagreement';

    public function __invoke(?Request $request = null): Response
    {
        $boardId = $request?->query['board'] ?? null;
        $filterQuery = trim($request?->query['q'] ?? '');
        $filterStatus = trim($request?->query['status'] ?? '');
        $filterPriority = trim($request?->query['priority'] ?? '');
        $filterWorkflow = trim($request?->query['workflow'] ?? '');

        $board = $this->board->board($boardId);

        // The card lane/status is agent-kanban's; lifecycle state and the next
        // step are agent-loop's. Both are shown side by side, never merged. A
        // card agent-loop cannot project keeps rendering from the board alone.
        $workflow = [];
        foreach ($board->cards as $card) {
            try {
                $workflow[$card->id] = $this->workflow->task($card->id);
            } catch (Throwable) {
                $workflow[$card->id] = null;
            }
        }

        $statuses = [];
        $kinds = [];
        $disagreements = 0;
        foreach ($board->cards as $card) {
            $statuses[$card->status] = true;
            $snapshot = $workflow[$card->id];
            if ($snapshot === null) {
                continue;
            }
            $kinds[$snapshot->nextActionKind] = ($kinds[$snapshot->nextActionKind] ?? 0) + 1;
            if ($snapshot->disagreements !== []) {
                ++$disagreements;
            }
        }
        ksort($statuses);
        ksort($kinds);

        $cards = array_values(array_filter(
            $board->cards,
            fn(CardSnapshot $card): bool => $this->matches($card, $workflow[$card->id], $filterQuery, $filterStatus, $filterPriority, $filterWorkflow),
        ));

        return Response::html($this->templates->render('board/index', [
            'board' => $board,
            'cards' => $cards,
            'workflow' => $workflow,
            'statuses' => array_keys($statuses),
            'kind_counts' => $kinds,
            'disagreement_count' => $disagreements,
            'filter_query' => $filterQuery,
            'filter_status' => $filterStatus,
            'filter_priority' => $filterPriority,
            'filter_workflow' => $filterWorkflow,
            'csrf_token' => $this->csrf->token(),
        ]));
    }

    private function matches(CardSnapshot $card, ?WorkflowSnapshot $workflow, string $query, string $status, string $priority, string $workflowFilter): bool
    {
        if ($status !== '' && $card->status !== $status) {
            return false;
        }
        if ($priority !== '' && (string) $card->priority !== $priority) {
            return false;
        }
        if ($workflowFilter !== '') {
            if ($workflow === null) {
                return false;
            }
            $hit = $workflowFilter === self::FILTER_DISAGREEMENT
                ? $workflow->disagreements !== []
                : $workflow->nextActionKind === $workflowFilter;
            if (!$hit) {
                return false;
            }
        }
        if ($query === '') {
            return true;
        }
        $needle = mb_strtolower($query);
        foreach ([$card->id, $card->title, $card->summary, $card->taskBrief, $card->assignee ?? ''] as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function newCard(Request $request): Response
    {
        $boardId = $request->query['board'] ?? null;
        $board = $this->board->board($boardId);
        $suggestedId = $this->mutation->suggestNextId($boardId);
        $lanes = $this->mutation->availableLanes($boardId);

        $initialTitle = $request->query['title'] ?? '';
        $initialSummary = $request->query['summary'] ?? '';
        $initialBrief = $request->query['brief'] ?? '';

        return Response::html($this->templates->render('board/new', [
            'board' => $board,
            'suggested_id' => $suggestedId,
            'lanes' => $lanes,
            'initial_title' => $initialTitle,
            'initial_summary' => $initialSummary,
            'initial_brief' => $initialBrief,
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
