<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use InvalidArgumentException;
use voku\AgentUi\Http\FlashNotice;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentKanban\CardMutationGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final readonly class TaskAction
{
    public function __construct(
        private BoardProjectionGateway $board,
        private WorkflowProjectionGateway $workflow,
        private HumanDecisionGateway $decisions,
        private RunnerGateway $runner,
        private ContextExplanationGateway $context,
        private TaskTransparencyGateway $transparency,
        private CardMutationGateway $cardMutation,
        private CsrfTokenManager $csrf,
        private TemplateRenderer $templates,
        private FlashNotice $notice = new FlashNotice(),
    ) {
    }

    public function __invoke(string $taskId): Response
    {
        $transparency = $this->transparency->task($taskId);
        $card = $this->board->card($taskId);

        return Response::html($this->templates->render('task/index', [
            'card' => $card,
            'workflow' => $this->workflow->task($taskId),
            'human_decisions' => $this->decisions->available($taskId),
            'contract' => $this->decisions->contract($taskId),
            'runner' => $this->runner->status($taskId),
            'context_explanation' => $this->context->task($taskId),
            'context_coverage' => $transparency->context,
            'task_transparency' => $transparency,
            'csrf_token' => $this->csrf->token(),
        ]));
    }

    public function edit(string $taskId): Response
    {
        $card = $this->board->card($taskId);
        $lanes = $this->cardMutation->availableLanes($card->boardId);

        return Response::html($this->templates->render('task/edit', [
            'card' => $card,
            'lanes' => $lanes,
            'csrf_token' => $this->csrf->token(),
        ]));
    }

    public function update(string $taskId, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        $card = $this->board->card($taskId);
        $title = $this->required($request, 'title', 500);
        $lane = $this->optionalString($request, 'lane');
        $status = $this->optionalString($request, 'status');
        $summary = $this->optionalString($request, 'summary') ?? '';
        $taskBrief = $this->optionalString($request, 'task_brief') ?? '';
        $nextAction = $this->optionalString($request, 'next_action') ?? '';
        $validation = $this->optionalString($request, 'validation') ?? '';
        $assignee = $this->optionalString($request, 'assignee');
        $expectedRevision = $this->optionalString($request, 'expected_revision');

        $priority = null;
        if (isset($request->body['priority']) && $request->body['priority'] !== '') {
            $p = (int) $request->body['priority'];
            if ($p >= 1 && $p <= 5) {
                $priority = $p;
            }
        }

        if ($lane !== null && $lane !== $card->lane) {
            $this->cardMutation->move($taskId, $lane, actor: $assignee, expectedRevision: $expectedRevision);
            $updatedCard = $this->board->card($taskId);
            $expectedRevision = $updatedCard->revision;
        }

        $this->cardMutation->update(
            $taskId,
            title: $title,
            status: $status,
            summary: $summary,
            taskBrief: $taskBrief,
            nextAction: $nextAction,
            validation: $validation,
            priority: $priority,
            assignee: $assignee,
            expectedRevision: $expectedRevision,
        );

        $this->notice->record(sprintf('Card %s updated successfully.', $taskId));

        return Response::redirect('/task/' . rawurlencode($taskId));
    }

    public function move(string $taskId, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        $targetLane = $this->required($request, 'target_lane', 50);
        $actor = $this->optionalString($request, 'actor');
        $expectedRevision = $this->optionalString($request, 'expected_revision');

        $this->cardMutation->move($taskId, $targetLane, actor: $actor, expectedRevision: $expectedRevision);

        $this->notice->record(sprintf('Card %s moved to %s.', $taskId, $targetLane));

        $returnTo = $this->optionalString($request, 'return_to');
        $redirectTarget = ($returnTo !== null && str_starts_with($returnTo, '/'))
            ? $returnTo
            : '/task/' . rawurlencode($taskId);

        return Response::redirect($redirectTarget);
    }

    public function claim(string $taskId, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        $actor = $this->required($request, 'actor', 200);
        $moveToDoing = ($request->body['move_to_doing'] ?? '') === '1';
        $expectedRevision = $this->optionalString($request, 'expected_revision');

        $this->cardMutation->claim($taskId, $actor, moveToDoing: $moveToDoing, expectedRevision: $expectedRevision);

        $this->notice->record(sprintf('Card %s claimed by %s.', $taskId, $actor));

        return Response::redirect('/task/' . rawurlencode($taskId));
    }

    public function release(string $taskId, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        $actor = $this->required($request, 'actor', 200);
        $expectedRevision = $this->optionalString($request, 'expected_revision');

        $this->cardMutation->release($taskId, $actor, expectedRevision: $expectedRevision);

        $this->notice->record(sprintf('Card %s claim released.', $taskId));

        return Response::redirect('/task/' . rawurlencode($taskId));
    }

    public function contractView(string $taskId): Response
    {
        $card = $this->board->card($taskId);
        $contract = $this->decisions->contract($taskId);

        return Response::html($this->templates->render('task/contract', [
            'card' => $card,
            'contract' => $contract,
            'csrf_token' => $this->csrf->token(),
        ]));
    }

    public function contractPropose(string $taskId, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);

        $goal = $this->required($request, 'goal', 1000);
        $plannedBy = $this->required($request, 'planned_by', 200);
        $action = ($request->body['contract_action'] ?? '') === 'revise' ? 'revise' : 'propose';

        $scope = $this->splitLines((string) ($request->body['scope'] ?? ''));
        $nonGoals = $this->splitLines((string) ($request->body['non_goals'] ?? ''));
        $validation = $this->splitLines((string) ($request->body['validation'] ?? ''));
        $acceptance = $this->splitLines((string) ($request->body['acceptance_criteria'] ?? ''));

        if ($action === 'revise') {
            $contract = $this->decisions->reviseContract(
                taskId: $taskId,
                goal: $goal,
                scope: $scope,
                nonGoals: $nonGoals,
                validation: $validation,
                plannedBy: $plannedBy,
                acceptanceCriteria: $acceptance,
            );
        } else {
            $contract = $this->decisions->proposeContract(
                taskId: $taskId,
                goal: $goal,
                scope: $scope,
                nonGoals: $nonGoals,
                validation: $validation,
                plannedBy: $plannedBy,
                acceptanceCriteria: $acceptance,
            );
        }

        $this->notice->record(sprintf('Contract revision %d proposed and awaiting approval.', $contract->revision));

        return Response::redirect('/task/' . rawurlencode($taskId));
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $raw) ?: []),
            static fn(string $line): bool => $line !== '',
        ));
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
