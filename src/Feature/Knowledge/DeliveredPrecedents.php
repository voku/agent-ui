<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Knowledge;

/**
 * What Recall's persisted context for one task says about LearningNote precedents.
 *
 * Three ways of not having an answer stay distinct from an empty one: the context was never
 * compiled, it could not be verified, or it was read and held no precedent. Collapsing them
 * would let "Recall never ran for this task" read as "no lesson applied".
 */
final readonly class DeliveredPrecedents
{
    public const string AVAILABLE = 'available';
    public const string MISSING = 'missing';
    public const string INVALID = 'invalid';

    /** @param list<DeliveredPrecedent> $items */
    public function __construct(
        public string $status,
        public ?string $problem,
        public array $items,
    ) {
    }
}
