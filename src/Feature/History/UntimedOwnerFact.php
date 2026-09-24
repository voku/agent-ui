<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\History;

/**
 * A fact this task has, which its owner does not say when it happened.
 *
 * Durable Guidance is the case that forced this type to exist: agent-learning
 * projects its id, type, status and lineage, and no timestamp at all. Dropping
 * it from the page would tell the reader it does not exist, and placing it after
 * the Proposal it came from would be the UI inventing chronology. Naming the
 * owner and the field that is missing is the only honest third option, and it
 * doubles as the list of owner APIs worth asking for.
 */
final readonly class UntimedOwnerFact
{
    public function __construct(
        public string $owner,
        public string $kind,
        public string $title,
        public string $detail,
        public string $missing,
    ) {
    }
}
