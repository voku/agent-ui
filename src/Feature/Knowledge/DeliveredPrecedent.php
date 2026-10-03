<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Knowledge;

/**
 * One LearningNote precedent as Recall's persisted explanation reports it for a task.
 *
 * `$delivered` is Recall's own `selected`: the note was rendered into the compiled
 * briefing. It says nothing about whether the work used it, and nothing here claims so.
 * `$taughtBy` is the tasks Learning's own chain names as the source (note -> source Finding
 * -> task), or `null` when that chain could not be followed - which is not the same as
 * "no task taught it".
 */
final readonly class DeliveredPrecedent
{
    /**
     * @param list<string>|null $taughtBy
     */
    public function __construct(
        public string $title,
        public bool $delivered,
        public string $state,
        public ?string $whyNot,
        public ?string $noteId,
        public ?array $taughtBy,
        public string $originNote,
    ) {
    }
}
