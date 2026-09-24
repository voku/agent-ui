<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

use Throwable;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;

/**
 * Assembles one task story from the owners that each hold part of it.
 *
 * The history page used to show four kinds of event, all of them agent-loop's,
 * and only for the Contract revision currently in force. The rest of what
 * happened to a task - when the card was written, what the earlier Contract
 * revisions said and when they were approved, which Findings and Proposals the
 * work produced - was spread over pages that each answered their own question,
 * so the order of events existed only in the reader's head.
 *
 * Composition here is deliberately mechanical: read each owner's projection,
 * copy the timestamp the owner published, sort. It decides nothing about
 * meaning, and where an owner publishes no timestamp the fact goes to
 * `$untimed` rather than being given a plausible-looking position.
 */
final readonly class TaskActivityComposer
{
    /**
     * Contract events this composer reads from the Contract store instead.
     *
     * Two owner projections answer "when was this Contract approved": the audit
     * report, for the revision in force, and the Contract store, for every
     * revision it retains. Reading both is how the current approval ended up on
     * the page twice.
     *
     * @var list<string>
     */
    private const array SOURCED_FROM_THE_CONTRACT_STORE = ['contract_approved'];

    public function __construct(
        private AuditTrailGateway $audit,
        private HumanDecisionGateway $decisions,
        private LearningCatalogGateway $learning,
        private BoardProjectionGateway $board,
    ) {
    }

    public function forTask(string $taskId): TaskActivity
    {
        $events = [];
        $untimed = [];

        foreach ($this->audit->task($taskId)->timeline as $entry) {
            if (in_array($entry->kind, self::SOURCED_FROM_THE_CONTRACT_STORE, true)) {
                // The audit projection reports the approval of the revision in
                // force; the Contract store reports every revision's, this one
                // included. Taking both rendered the current approval twice.
                continue;
            }
            $events[] = new TaskActivityEvent(
                $entry->at,
                'agent-loop',
                $entry->kind,
                $entry->title,
                $entry->detail,
            );
        }

        $this->addBoard($taskId, $events);
        $this->addContracts($taskId, $events);
        $this->addLearning($taskId, $events, $untimed);

        usort(
            $events,
            static fn(TaskActivityEvent $left, TaskActivityEvent $right): int => strcmp($right->at, $left->at),
        );

        return new TaskActivity($events, $untimed);
    }

    /**
     * The card's own creation time, which agent-kanban models as nullable.
     *
     * A card whose file carries no parsable created date is exactly the case the
     * owner already represents as null; the UI does not fill it from the file.
     *
     * @param list<TaskActivityEvent> $events
     */
    private function addBoard(string $taskId, array &$events): void
    {
        try {
            $card = $this->board->card($taskId);
        } catch (Throwable) {
            // A task agent-loop governs need not have a board card at all.
            return;
        }

        if ($card->createdAt === null) {
            return;
        }

        $events[] = new TaskActivityEvent(
            $card->createdAt,
            'agent-kanban',
            'task_created',
            'Task created',
            $card->title,
        );
    }

    /**
     * Every Contract revision the owner still holds, not only the one in force.
     *
     * `agent-loop` keeps superseded revisions with their own createdAt and
     * approvedAt, so the history of what was agreed and when is owner-published
     * rather than something the UI has to reconstruct from the current one.
     *
     * @param list<TaskActivityEvent> $events
     */
    private function addContracts(string $taskId, array &$events): void
    {
        $revisions = $this->decisions->supersededRevisions($taskId);
        $current = $this->decisions->contract($taskId);
        if ($current !== null) {
            $revisions[] = $current;
        }

        foreach ($revisions as $contract) {
            $events[] = new TaskActivityEvent(
                $contract->createdAt,
                'agent-loop',
                'contract_proposed',
                'Contract proposed',
                $this->contractDetail($contract, 'Planned by ' . $contract->plannedBy),
            );

            if ($contract->approvedAt === null) {
                continue;
            }

            $events[] = new TaskActivityEvent(
                $contract->approvedAt,
                'agent-loop',
                'contract_approved',
                'Contract approved',
                $this->contractDetail(
                    $contract,
                    $contract->approvedBy === null ? 'Approved' : 'Approved by ' . $contract->approvedBy,
                ),
            );
        }
    }

    private function contractDetail(TaskContract $contract, string $actor): string
    {
        return 'Revision ' . $contract->revision . '. ' . $actor . '.';
    }

    /**
     * What the work taught, as agent-learning timestamps it - and what it does not.
     *
     * @param list<TaskActivityEvent> $events
     * @param list<UntimedOwnerFact> $untimed
     */
    private function addLearning(string $taskId, array &$events, array &$untimed): void
    {
        $projection = $this->learning->task($taskId);

        foreach ($projection->findings as $finding) {
            $events[] = new TaskActivityEvent(
                $finding->createdAt,
                'agent-learning',
                'finding_created',
                'Finding created',
                $finding->id . ' · ' . $finding->status,
            );
        }

        foreach ($projection->proposals as $proposal) {
            $events[] = new TaskActivityEvent(
                $proposal->createdAt,
                'agent-learning',
                'proposal_created',
                'Proposal created',
                $proposal->id . ' · ' . $proposal->action,
            );

            if ($proposal->approvedAt === null) {
                continue;
            }

            $events[] = new TaskActivityEvent(
                $proposal->approvedAt,
                'agent-learning',
                'proposal_approved',
                'Proposal approved',
                $proposal->id
                    . ($proposal->approvedBy === null ? '' : ' by ' . $proposal->approvedBy),
            );
        }

        foreach ($projection->guidance as $guidance) {
            $untimed[] = new UntimedOwnerFact(
                'agent-learning',
                'guidance',
                'Durable guidance ' . $guidance->id,
                $guidance->type->value . ' · ' . $guidance->status,
                'GuidanceProjection publishes no timestamp for promotion.',
            );
        }
    }
}
