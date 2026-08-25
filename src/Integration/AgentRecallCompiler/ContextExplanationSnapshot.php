<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentRecallCompiler;

use voku\AgentRecallCompiler\Output\CompiledContextExplanation;
use voku\AgentRecallCompiler\Output\CompiledGuidanceDecision;

/**
 * UI-safe availability wrapper around Recall's persisted explanation.
 *
 * The explanation itself remains Recall-owned. This wrapper only distinguishes
 * whether that owner projection is available, absent, or could not be verified
 * without leaking local filesystem details into the browser.
 */
final readonly class ContextExplanationSnapshot
{
    public const string AVAILABLE = 'available';
    public const string MISSING = 'missing';
    public const string INVALID = 'invalid';

    private function __construct(
        public string $status,
        public ?CompiledContextExplanation $explanation,
        public ?string $problem,
    ) {
    }

    public static function available(CompiledContextExplanation $explanation): self
    {
        return new self(self::AVAILABLE, $explanation, null);
    }

    public static function missing(): self
    {
        return new self(
            self::MISSING,
            null,
            'No persisted compiled context exists for this task. Viewing this page never recompiles Recall.',
        );
    }

    public static function invalid(): self
    {
        return new self(
            self::INVALID,
            null,
            'Persisted Recall context could not be verified. Recompile it through the Recall owner before relying on this context.',
        );
    }

    public function isAvailable(): bool
    {
        return $this->explanation !== null;
    }

    public function selectedGuidanceCount(): int
    {
        if ($this->explanation === null) {
            return 0;
        }

        return count(array_filter(
            $this->explanation->guidance,
            static fn(CompiledGuidanceDecision $guidance): bool => $guidance->selected,
        ));
    }

    public function excludedGuidanceCount(): int
    {
        if ($this->explanation === null) {
            return 0;
        }

        return count(array_filter(
            $this->explanation->guidance,
            static fn(CompiledGuidanceDecision $guidance): bool => !$guidance->selected,
        ));
    }
}
