<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

use InvalidArgumentException;

/**
 * One thing that happened to a task, at a time its owner published.
 *
 * `$at` is copied from the owner's own field - a Card's createdAt, a Contract's
 * approvedAt, a Finding's createdAt. It is never parsed out of an identifier,
 * read from a file's mtime, or inferred from a neighbouring event: a position on
 * a timeline is a claim about when something happened, and the UI is not
 * entitled to make that claim on an owner's behalf.
 */
final readonly class TaskActivityEvent
{
    public function __construct(
        public string $at,
        public string $owner,
        public string $kind,
        public string $title,
        public string $detail,
    ) {
        // Enforced here rather than left to a test, because the mistake this
        // guards against is a caller reaching for `?? ''` when an owner's
        // timestamp turns out to be nullable. A blank string renders as a blank
        // cell and sorts to the end: wrong, and quiet about it. A fact with no
        // owner time is an UntimedOwnerFact, and this type refuses to be one.
        if (trim($at) === '') {
            throw new InvalidArgumentException(
                'A timeline event needs the timestamp its owner published; ' . $kind
                    . ' has none, so it belongs in the untimed list.',
            );
        }
        if (trim($owner) === '') {
            throw new InvalidArgumentException('A timeline event must name the owner that published it: ' . $kind . '.');
        }
    }
}
