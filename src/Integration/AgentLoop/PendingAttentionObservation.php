<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Execution\AttentionRequest;

/**
 * Three-valued owner observation: pending, validly absent, or unavailable.
 *
 * A projection failure must never be shown as proof that no human decision is
 * waiting. It has no authority beyond the source ExecutionGateway projection.
 */
final readonly class PendingAttentionObservation
{
    public function __construct(
        public ?AttentionRequest $attention,
        public bool $available = true,
    ) {
    }
}
