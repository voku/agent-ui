<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\HumanDecision;

use InvalidArgumentException;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Security\CsrfTokenManager;

final readonly class HumanDecisionAction
{
    public function __construct(
        private HumanDecisionGateway $decisions,
        private CsrfTokenManager $csrf,
    ) {
    }

    /** @param 'approve'|'review_ack'|'learning' $route */
    public function __invoke(string $taskId, string $route, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);
        $actor = $this->required($request, 'actor', 200);

        match ($route) {
            'approve' => $this->decisions->approve($taskId, $actor),
            'review_ack' => $this->decisions->acknowledgeReview(
                $taskId,
                $this->required($request, 'report_sha256', 80),
                $actor,
            ),
            'learning' => $this->learning($taskId, $actor, $request),
        };

        return Response::redirect('/task/' . rawurlencode($taskId));
    }

    private function learning(string $taskId, string $actor, Request $request): void
    {
        $decision = $this->required($request, 'decision', 40);
        if (!in_array($decision, ['no_durable_learning', 'follow_up_required'], true)) {
            throw new InvalidArgumentException('Unsupported Learning decision for this UI form.');
        }

        $followUpRef = null;
        if ($decision === 'follow_up_required') {
            $followUpRef = $this->required($request, 'follow_up_ref', 500);
        }

        $this->decisions->recordLearning(
            $taskId,
            $decision,
            $actor,
            $this->required($request, 'reason', 2000),
            $followUpRef,
        );
    }

    private function required(Request $request, string $key, int $maxLength): string
    {
        $value = trim($request->body[$key] ?? '');
        if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty value of at most %d bytes.', $key, $maxLength));
        }

        return $value;
    }
}
