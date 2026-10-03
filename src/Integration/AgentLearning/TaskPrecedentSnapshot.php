<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLearning;

use voku\AgentLearning\LearningNoteProjection;
use voku\AgentLearning\Lineage\LearningLineageProjector;
use voku\AgentLearning\Lineage\LearningTaskPrecedentResult;

/**
 * UI-safe wrapper around Learning's answer to "which notes did this task teach?".
 *
 * The answer is Learning's; this wrapper distinguishes four ways of not having
 * it, because a page that shows nothing for all of them would let "Learning has
 * not been set up" and "the projection is behind" read as "this task taught
 * nothing". Viewing never rebuilds the projection: a stale or absent one is
 * reported, and repairing it is a Learning command.
 */
final readonly class TaskPrecedentSnapshot
{
    public const string AVAILABLE = 'available';
    public const string NO_LEARNING = 'no_learning';
    public const string PROJECTION_UNAVAILABLE = 'projection_unavailable';
    public const string INVALID = 'invalid';

    private function __construct(
        public string $status,
        public ?LearningTaskPrecedentResult $result,
        public ?string $problem,
    ) {
    }

    public static function available(LearningTaskPrecedentResult $result): self
    {
        return new self(self::AVAILABLE, $result, null);
    }

    public static function noLearning(): self
    {
        return new self(self::NO_LEARNING, null, 'This project has no Learning root, so no notes can be listed.');
    }

    public static function projectionUnavailable(): self
    {
        return new self(
            self::PROJECTION_UNAVAILABLE,
            null,
            'Learning\'s lineage projection is absent or behind the durable records, so the notes derived from this task cannot be listed yet. Viewing this page never rebuilds it; rebuild it through agent-learning.',
        );
    }

    public static function invalid(): self
    {
        return new self(
            self::INVALID,
            null,
            'Learning could not answer for this task. Check the Learning records through agent-learning before relying on this page.',
        );
    }

    public function isAvailable(): bool
    {
        return $this->result !== null;
    }

    /**
     * Notes this task's own lineage produced - not every active note.
     *
     * `precedentsForTask()` also tops its list up with other active notes so
     * that Recall can match them by scope; those are not something this task
     * taught, and listing them here would claim lineage Learning never
     * recorded. A note belongs here only when Learning's lineage has an
     * explicit note-derivation relation pointing at it.
     *
     * @return list<LearningNoteProjection>
     */
    public function taughtNotes(): array
    {
        if ($this->result === null) {
            return [];
        }

        $derived = [];
        foreach ($this->result->lineage->relations as $relation) {
            if (in_array($relation->kind, [LearningLineageProjector::NOTE_FROM_FINDING, LearningLineageProjector::NOTE_FROM_PROPOSAL], true)) {
                $derived[$relation->targetId] = true;
            }
        }

        return array_values(array_filter(
            $this->result->precedents,
            static fn(LearningNoteProjection $note): bool => isset($derived[$note->id]),
        ));
    }
}
