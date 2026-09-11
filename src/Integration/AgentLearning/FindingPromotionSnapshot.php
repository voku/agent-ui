<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLearning;

use voku\AgentLearning\LearningNotePromotionReadiness;

/**
 * agent-learning's answer to "could this Finding ever be seen again".
 *
 * The verdict and the blocker vocabulary are the owner's. This model carries
 * them and adds a label per blocker, which is presentation; it never decides
 * whether a Finding is promotable and never suggests promoting one.
 */
final readonly class FindingPromotionSnapshot
{
    /** @param list<string> $blockers owner blocker identifiers, in the owner's order */
    private function __construct(
        public string $findingId,
        public bool $promotable,
        public array $blockers,
        public ?string $patternKey,
    ) {
    }

    public static function fromOwner(LearningNotePromotionReadiness $readiness): self
    {
        return new self(
            $readiness->findingId,
            $readiness->promotable,
            $readiness->blockers,
            $readiness->patternKey,
        );
    }

    /**
     * A human label for one owner blocker.
     *
     * An identifier this build does not know is rendered as itself rather than
     * guessed at, so a newer owner never has its vocabulary silently reworded.
     */
    public static function blockerLabel(string $blocker): string
    {
        return match ($blocker) {
            LearningNotePromotionReadiness::BLOCKER_MISSING => 'the Finding no longer exists',
            LearningNotePromotionReadiness::BLOCKER_STATUS => 'its state is not one a note can come from',
            LearningNotePromotionReadiness::BLOCKER_CLASSIFICATION => 'nobody classified it as one to keep',
            LearningNotePromotionReadiness::BLOCKER_PATTERN_KEY => 'it has no pattern key to file it under',
            LearningNotePromotionReadiness::BLOCKER_VALIDATION_CASE => 'it has no validation case saying when it applies',
            LearningNotePromotionReadiness::BLOCKER_VALIDATED_CONCLUSION => 'it records no validated conclusion',
            default => $blocker,
        };
    }
}
