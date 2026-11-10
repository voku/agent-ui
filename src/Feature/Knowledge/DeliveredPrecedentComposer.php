<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Knowledge;

use voku\AgentLearning\LearningNoteProjection;
use voku\AgentRecallCompiler\Output\CompiledContextExplainItem;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;

/**
 * Joins two owners' facts about one precedent without deciding anything about it.
 *
 * Recall says which LearningNote it put in front of a task and, since 0.25.2, names the note
 * as a typed field. Learning says which Findings a note came from and which task each Finding
 * belongs to. Following those links is a lookup, so a precedent whose chain cannot be followed
 * (the note is outside Learning's bounded precedent set, or its projection is behind) is shown
 * with that reason rather than given an origin.
 */
final readonly class DeliveredPrecedentComposer
{
    /** Recall's documented explain kind for a LearningNote precedent. */
    private const string PRECEDENT_KIND = 'learning_precedent';

    public function __construct(
        private ContextExplanationGateway $recall,
        private LearningCatalogGateway $learning,
    ) {
    }

    public function forTask(string $taskId): DeliveredPrecedents
    {
        $snapshot = $this->recall->task($taskId);
        if ($snapshot->explanation === null) {
            return new DeliveredPrecedents(
                $snapshot->status === ContextExplanationSnapshot::MISSING ? DeliveredPrecedents::MISSING : DeliveredPrecedents::INVALID,
                $snapshot->problem,
                [],
            );
        }

        $precedents = $this->learning->taskPrecedents($taskId);
        $notes = [];
        foreach ($precedents->result->precedents ?? [] as $note) {
            $notes[$note->id] = $note;
        }

        $items = [];
        foreach ($snapshot->explanation->items as $item) {
            if ($item->kind !== self::PRECEDENT_KIND) {
                continue;
            }
            $items[] = $this->precedent($item, $notes, $precedents->problem);
        }

        return new DeliveredPrecedents(DeliveredPrecedents::AVAILABLE, null, $items);
    }

    /** @param array<string, LearningNoteProjection> $notes */
    private function precedent(CompiledContextExplainItem $item, array $notes, ?string $projectionProblem): DeliveredPrecedent
    {
        $taughtBy = null;
        $originNote = 'Recall did not record which note this is, so its origin cannot be followed.';
        if ($item->subjectId !== null) {
            $note = $notes[$item->subjectId] ?? null;
            if ($note === null) {
                $originNote = $projectionProblem ?? 'Learning did not return this note in its bounded precedent set, so its origin cannot be followed from here.';
            } else {
                $taughtBy = $this->tasksFor($note);
                $originNote = $taughtBy === [] ? 'Learning records no task for this note\'s source Findings.' : '';
            }
        }

        return new DeliveredPrecedent(
            $item->what,
            $item->selected,
            $item->state->value,
            $item->whyNot,
            $item->subjectId,
            $taughtBy,
            $originNote,
        );
    }

    /** @return list<string> */
    private function tasksFor(LearningNoteProjection $note): array
    {
        $tasks = [];
        foreach ($note->sourceFindings as $findingId) {
            $finding = $this->learning->finding($findingId);
            if ($finding !== null) {
                $tasks[$finding->taskId] = true;
            }
        }
        $ids = array_keys($tasks);
        sort($ids, SORT_STRING);

        return array_map(strval(...), $ids);
    }

    /**
     * Map of noteId => list<string> for tasks whose compiled Recall context received that note.
     *
     * @return array<string, list<string>>
     */
    public function downstreamDeliveries(): array
    {
        return $this->recall->deliveredPrecedentsByNote();
    }

    /**
     * Finds active notes derived from a finding and their downstream task deliveries.
     *
     * @return list<array{note: LearningNoteProjection, deliveries: list<string>}>
     */
    public function findingLineage(string $findingId): array
    {
        $finding = $this->learning->finding($findingId);
        if ($finding === null) {
            return [];
        }

        $precedents = $this->learning->taskPrecedents($finding->taskId);
        if (!$precedents->isAvailable()) {
            return [];
        }

        $allDeliveries = $this->downstreamDeliveries();
        $derived = [];
        foreach ($precedents->taughtNotes() as $note) {
            if (in_array($findingId, $note->sourceFindings, true)) {
                $derived[] = [
                    'note' => $note,
                    'deliveries' => $allDeliveries[$note->id] ?? [],
                ];
            }
        }

        return $derived;
    }
}
