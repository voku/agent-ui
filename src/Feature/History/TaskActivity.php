<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

/**
 * The task's story, split by whether its owner can place a fact in time.
 *
 * @param list<TaskActivityEvent> $events  newest first, every one owner-timestamped
 * @param list<UntimedOwnerFact>  $untimed known to exist, with no owner timestamp to place it
 */
final readonly class TaskActivity
{
    /**
     * @param list<TaskActivityEvent> $events
     * @param list<UntimedOwnerFact> $untimed
     */
    public function __construct(
        public array $events,
        public array $untimed,
    ) {
    }
}
