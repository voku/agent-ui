<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskAuditSnapshot;

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
        private HumanDecisionGateway $decisions,
        private LearningCatalogGateway $learning,
        private BoardProjectionGateway $board,
    ) {
    }

    /**
     * Composes the story around an audit snapshot the caller has already read.
     *
     * The snapshot is a parameter rather than something this class fetches
     * because the page needs it too, and reading it twice cost a second set of
     * four owner store reads for one request - long enough to measure, and long
     * enough for the two copies to disagree if a run wrote between them. Taking
     * it as an argument makes the single read structural instead of a habit, and
     * the snapshot's own task id is the owner-normalised one every other
     * projection here is then asked for.
     */
    public function forTask(TaskAuditSnapshot $audit): TaskActivity
    {
        $taskId = $audit->taskId;
        $events = [];
        $untimed = [];

        foreach ($audit->timeline as $entry) {
            if (in_array($entry->kind, self::SOURCED_FROM_THE_CONTRACT_STORE, true)) {
                // The audit projection reports the approval of the revision in
                // force; the Contract store reports every revision's, this one
                // included. Taking both rendered the current approval twice.
                continue;
            }
            $this->place($events, $untimed, $entry->at, 'agent-loop', $entry->kind, $entry->title, $entry->detail);
        }

        $this->addBoard($taskId, $events, $untimed);
        $this->addContracts($taskId, $events, $untimed);
        $this->addLearning($taskId, $events, $untimed);

        usort($events, $this->newestFirst(...));

        return new TaskActivity($events, $untimed);
    }

    /**
     * Puts a fact on the timeline, or on the list of facts that have no place on it.
     *
     * Every owner field that feeds this page is nullable or free text somewhere
     * in its own store, so the question "can this be placed" has to be asked of
     * each value rather than assumed from the type. The two answers are a
     * timeline entry and a named absence; there is no third answer where the UI
     * picks a position, which is what a string sort was quietly doing.
     *
     * @param list<TaskActivityEvent> $events
     * @param list<UntimedOwnerFact> $untimed
     */
    private function place(
        array &$events,
        array &$untimed,
        ?string $at,
        string $owner,
        string $kind,
        string $title,
        string $detail,
    ): void {
        if ($at !== null && OwnerInstant::parse($at) !== null) {
            $events[] = new TaskActivityEvent($at, $owner, $kind, $title, $detail);

            return;
        }

        $untimed[] = new UntimedOwnerFact(
            $owner,
            $kind,
            $title,
            $detail,
            $at === null || trim($at) === ''
                ? $owner . ' publishes no time for this.'
                : $owner . ' published "' . $at . '", which names no moment this page can place.',
        );
    }

    /**
     * Newest first by instant, not by the string an owner happened to format.
     *
     * Every timestamp here is ATOM, but ATOM carries an offset: `2026-01-01T09:00:00+02:00`
     * is earlier than `2026-01-01T08:00:00+00:00` and sorts after it as text. The
     * owners in this repository all write `+00:00` today, which is exactly why a
     * string comparison would keep working until the day one of them did not.
     * The original strings are still what gets rendered; only the ordering reads
     * the instant.
     *
     * Two owners can publish the same instant, and then the page has nothing to
     * order them by. It used to fall out of the order these methods happen to
     * run in, so moving the addLearning() call above addBoard() would have
     * reordered a rendered page with nothing in the diff to say so. Kind and
     * title are at least properties of the facts themselves.
     */
    private function newestFirst(TaskActivityEvent $left, TaskActivityEvent $right): int
    {
        $order = $this->instant($right) <=> $this->instant($left);
        if ($order !== 0) {
            return $order;
        }

        return [$left->kind, $left->title, $left->detail, $left->owner]
            <=> [$right->kind, $right->title, $right->detail, $right->owner];
    }

    private function instant(TaskActivityEvent $event): DateTimeImmutable
    {
        // Unreachable: TaskActivityEvent refuses to exist with a timestamp this
        // page cannot place, and place() routes those to the untimed list. If it
        // ever fires, the guard has a hole and the page should say so rather than
        // sort a word against a moment.
        return OwnerInstant::parse($event->at)
            ?? throw new LogicException('A placed event carries an unplaceable timestamp: ' . $event->at);
    }

    /**
     * The card's own creation time, which agent-kanban models as nullable.
     *
     * A card whose file carries no parsable created date is exactly the case the
     * owner already represents as null; the UI does not fill it from the file.
     * It does still say the card exists: a card with no Created line is a card,
     * and leaving the row out entirely reads as "this task was never on a
     * board", which is a different and untrue statement.
     *
     * @param list<TaskActivityEvent> $events
     * @param list<UntimedOwnerFact> $untimed
     */
    private function addBoard(string $taskId, array &$events, array &$untimed): void
    {
        try {
            $card = $this->board->card($taskId);
        } catch (InvalidArgumentException) {
            // The one absence the board models: a task agent-loop governs need
            // not have a card at all. Every other failure - a malformed card, an
            // unreadable board root - is a real failure, and swallowing it here
            // would drop the creation event while the page still read as the
            // complete story. Those surface instead.
            return;
        }

        $this->place($events, $untimed, $card->createdAt, 'agent-kanban', 'task_created', 'Task created', $card->title);
    }

    /**
     * Every Contract revision the owner still holds, not only the one in force.
     *
     * `agent-loop` keeps superseded revisions with their own createdAt and
     * approvedAt, so the history of what was agreed and when is owner-published
     * rather than something the UI has to reconstruct from the current one.
     *
     * @param list<TaskActivityEvent> $events
     * @param list<UntimedOwnerFact> $untimed
     */
    private function addContracts(string $taskId, array &$events, array &$untimed): void
    {
        $revisions = $this->decisions->supersededRevisions($taskId);
        $current = $this->decisions->contract($taskId);
        if ($current !== null) {
            $revisions[] = $current;
        }

        foreach ($revisions as $contract) {
            $this->place(
                $events,
                $untimed,
                $contract->createdAt,
                'agent-loop',
                'contract_proposed',
                'Contract proposed',
                $this->contractDetail($contract, 'Planned by ' . $contract->plannedBy),
            );

            if ($contract->approvedAt === null) {
                // Not an absence to report: an unapproved revision has not been
                // approved, and saying when it was not is not a missing field.
                continue;
            }

            $this->place(
                $events,
                $untimed,
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
            $this->place(
                $events,
                $untimed,
                $finding->createdAt,
                'agent-learning',
                'finding_created',
                'Finding created',
                $finding->id . ' · ' . $finding->status,
            );
        }

        foreach ($projection->proposals as $proposal) {
            $this->place(
                $events,
                $untimed,
                $proposal->createdAt,
                'agent-learning',
                'proposal_created',
                'Proposal created',
                $proposal->id . ' · ' . $proposal->action,
            );

            if ($proposal->approvedAt !== null) {
                $this->place(
                    $events,
                    $untimed,
                    $proposal->approvedAt,
                    'agent-learning',
                    'proposal_approved',
                    'Proposal approved',
                    $this->byActor($proposal->id, $proposal->approvedBy),
                );
            }

            // The transitions after approval, each dated by agent-learning since
            // 0.18.26. A null moment means that transition has not happened -
            // an absence, not a gap - so it contributes nothing, just as an
            // unapproved Contract revision contributes no approval.
            foreach ([
                ['proposal_acknowledged', 'Proposal acknowledged', $proposal->acknowledgedAt, $proposal->acknowledgedBy],
                ['proposal_applied', 'Proposal applied', $proposal->appliedAt, $proposal->appliedBy],
                ['proposal_retired', 'Proposal retired', $proposal->retiredAt, $proposal->retiredBy],
            ] as [$kind, $title, $at, $by]) {
                if ($at !== null) {
                    $this->place($events, $untimed, $at, 'agent-learning', $kind, $title, $this->byActor($proposal->id, $by));
                }
            }
        }

        foreach ($projection->guidance as $guidance) {
            // Guidance is its source proposal, promoted: the moment it became
            // durable is that proposal's applied moment, already placed above
            // as proposal_applied. Placing it again would put one fact on the
            // page twice. What is left is the one case the owner cannot date -
            // guidance recorded as applied with no applied time - and that is
            // listed rather than dropped. Guidance approved but not yet applied
            // has not become durable, which is not a missing time.
            if ($guidance->appliedAt !== null || $guidance->status !== 'applied') {
                continue;
            }
            $untimed[] = new UntimedOwnerFact(
                'agent-learning',
                'guidance',
                'Durable guidance ' . $guidance->id,
                $guidance->type->value . ' · ' . $guidance->status,
                'agent-learning records this guidance as applied but publishes no applied time for it.',
            );
        }
    }

    private function byActor(string $id, ?string $actor): string
    {
        return $id . ($actor === null ? '' : ' by ' . $actor);
    }
}
