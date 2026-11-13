<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Board;

use InvalidArgumentException;
use voku\AgentUi\Http\FlashNotice;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardMutationGateway;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGatewayInterface;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerSnapshot;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class BoardAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private CardMutationGateway $mutation,
        private WorkflowProjectionGateway $workflow,
        private HumanDecisionGateway $decisions,
        private CsrfTokenManager $csrf,
        private TemplateRenderer $templates,
        private ?RunnerGatewayInterface $runner = null,
        private FlashNotice $notice = new FlashNotice(),
    ) {
    }

    /** Filter value for cards whose board and workflow owners disagree. */
    public const string FILTER_DISAGREEMENT = 'disagreement';

    /** Filter value for cards waiting on a human decision or approval. */
    public const string FILTER_DECISION_REQUIRED = 'decision_required';

    /** Filter value for cards currently undergoing execution. */
    public const string FILTER_EXECUTING = 'executing';

    /** Filter value for cards in blocked or interrupted state. */
    public const string FILTER_BLOCKED = 'blocked';

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
        $workflow = $this->workflow->tasks(array_map(static fn(CardSnapshot $card): string => $card->id, $board->cards));

        /** @var array<string, RunnerSnapshot|null> $runners */
        $runners = [];
        if ($this->runner !== null && $this->runner->isInstalled()) {
            foreach ($board->cards as $card) {
                $runners[$card->id] = $this->runner->observe($card->id);
            }
        }

        $statuses = [];
        $kinds = [];
        $disagreements = 0;
        $executingCount = 0;
        $blockedCount = 0;
        $humanDecisionCount = 0;
        $pendingAttention = [];
        $attentionUnavailable = [];
        foreach ($board->cards as $card) {
            $statuses[$card->status] = true;
            $snapshot = $workflow[$card->id] ?? null;
            $runnerSnapshot = $runners[$card->id] ?? null;
            if ($snapshot !== null) {
                $kinds[$snapshot->nextActionKind] = ($kinds[$snapshot->nextActionKind] ?? 0) + 1;
                if ($snapshot->disagreements !== []) {
                    ++$disagreements;
                }
                if ($snapshot->state === 'blocked' || $card->lane === 'BLOCKED' || ($runnerSnapshot !== null && $runnerSnapshot->observationStatus === 'interrupted')) {
                    ++$blockedCount;
                }
            } elseif ($card->lane === 'BLOCKED' || ($runnerSnapshot !== null && $runnerSnapshot->observationStatus === 'interrupted')) {
                ++$blockedCount;
            }
            if ($runnerSnapshot !== null && $runnerSnapshot->installed && $runnerSnapshot->observationStatus === 'working') {
                ++$executingCount;
            }

            // Pending Attention is owned by Execution even when workflow projection fails.
            if ($snapshot === null || $snapshot->mode === 'governed') {
                $observation = $this->decisions->pendingAttentionObservation($card->id);
                if (!$observation->available) {
                    $attentionUnavailable[$card->id] = true;
                } elseif ($observation->attention !== null) {
                    $pendingAttention[$card->id] = $observation->attention;
                }
            }

            if ($snapshot?->nextActionKind === self::FILTER_DECISION_REQUIRED || isset($pendingAttention[$card->id])) {
                ++$humanDecisionCount;
            }
        }
        ksort($statuses);
        ksort($kinds);

        $cards = array_values(array_filter(
            $board->cards,
            fn(CardSnapshot $card): bool => $this->matches(
                $card,
                $workflow[$card->id] ?? null,
                $runners[$card->id] ?? null,
                isset($pendingAttention[$card->id]),
                $filterQuery,
                $filterStatus,
                $filterPriority,
                $filterWorkflow,
            ),
        ));

        return Response::html($this->templates->render('board/index', [
            'board' => $board,
            'cards' => $cards,
            'workflow' => $workflow,
            'runners' => $runners,
            'statuses' => array_keys($statuses),
            'kind_counts' => $kinds,
            'human_decision_count' => $humanDecisionCount,
            'pending_attention' => $pendingAttention,
            'attention_unavailable_ids' => array_keys($attentionUnavailable),
            'disagreement_count' => $disagreements,
            'executing_count' => $executingCount,
            'blocked_count' => $blockedCount,
            'filter_query' => $filterQuery,
            'filter_status' => $filterStatus,
            'filter_priority' => $filterPriority,
            'filter_workflow' => $filterWorkflow,
            'csrf_token' => $this->csrf->token(),
        ]));
    }

    private function matches(
        CardSnapshot $card,
        ?WorkflowSnapshot $workflow,
        ?RunnerSnapshot $runner,
        bool $hasPendingAttention,
        string $query,
        string $status,
        string $priority,
        string $workflowFilter,
    ): bool {
        if ($status !== '' && $card->status !== $status) {
            return false;
        }
        if ($priority !== '' && (string) $card->priority !== $priority) {
            return false;
        }
        if ($workflowFilter !== '') {
            $hit = match ($workflowFilter) {
                self::FILTER_DISAGREEMENT => $workflow !== null && $workflow->disagreements !== [],
                self::FILTER_DECISION_REQUIRED => $workflow?->nextActionKind === self::FILTER_DECISION_REQUIRED || $hasPendingAttention,
                self::FILTER_EXECUTING => $runner !== null && $runner->installed && $runner->observationStatus === 'working',
                self::FILTER_BLOCKED => ($workflow !== null && $workflow->state === 'blocked')
                    || $card->lane === 'BLOCKED'
                    || ($runner !== null && $runner->observationStatus === 'interrupted'),
                default => $workflow !== null && $workflow->nextActionKind === $workflowFilter,
            };
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
