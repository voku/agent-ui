<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\HumanDecision;

use InvalidArgumentException;
use voku\AgentUi\Http\FlashNotice;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Security\CsrfTokenManager;

final readonly class HumanDecisionAction
{
    public function __construct(
        private HumanDecisionGateway $decisions,
        private CsrfTokenManager $csrf,
        private FlashNotice $notice = new FlashNotice(),
    ) {
    }

    /** @param 'approve'|'reject'|'review_ack'|'learning'|'attention' $route */
    public function __invoke(string $taskId, string $route, Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);
        $returnPath = $this->returnPath($taskId, $request);
        $actor = $this->required($request, 'actor', 200);

        $recorded = match ($route) {
            'approve' => $this->approve($taskId, $actor, $request),
            'reject' => $this->reject($taskId, $actor, $request),
            'review_ack' => $this->acknowledgeReview($taskId, $actor, $request),
            'learning' => $this->learning($taskId, $actor, $request),
            'attention' => $this->attention($taskId, $actor, $request),
        };

        $this->notice->record($recorded);

        return Response::redirect($returnPath);
    }

    private function approve(string $taskId, string $actor, Request $request): string
    {
        $revision = $this->revision($request, 'approve');
        $this->decisions->approve($taskId, $actor, $revision, $this->reviewedHead($request));

        return sprintf('agent-loop recorded the Contract approval for %s by %s.', $taskId, $actor);
    }

    private function reject(string $taskId, string $actor, Request $request): string
    {
        $revision = $this->revision($request, 'reject');
        $reason = $this->required($request, 'reason', 4000);
        $this->decisions->reject($taskId, $actor, $revision, $reason, $this->reviewedHead($request));

        return sprintf('agent-loop recorded the rejection of Contract revision %d for %s by %s. Revise the Contract before work continues.', $revision, $taskId, $actor);
    }

    private function revision(Request $request, string $verb): int
    {
        $reviewed = $request->body['revision'] ?? null;
        $revision = is_string($reviewed) && preg_match('/^[1-9][0-9]*$/D', $reviewed) === 1
            ? filter_var($reviewed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if (!is_int($revision)) {
            throw new InvalidArgumentException(sprintf('A positive Contract revision is required to %s this task.', $verb));
        }

        return $revision;
    }

    /** The HEAD the human reviewed; agent-loop refuses the decision when it moved. */
    private function reviewedHead(Request $request): ?string
    {
        $head = trim($request->body['head_commit'] ?? '');
        if ($head === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{40,64}$/D', $head) !== 1) {
            throw new InvalidArgumentException('The reviewed repository HEAD is malformed; reload the page.');
        }

        return $head;
    }

    private function acknowledgeReview(string $taskId, string $actor, Request $request): string
    {
        $digest = $this->required($request, 'report_sha256', 80);
        $this->decisions->acknowledgeReview($taskId, $digest, $actor);

        return sprintf('agent-loop recorded the review acknowledgement by %s for report %s.', $actor, $digest);
    }

    private function learning(string $taskId, string $actor, Request $request): string
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

        return sprintf('agent-learning recorded the "%s" decision for %s.', str_replace('_', ' ', $decision), $taskId);
    }

    private function attention(string $taskId, string $actor, Request $request): string
    {
        $attentionId = $this->required($request, 'attention_id', 120);
        $responseValue = trim($request->body['response'] ?? '');
        $response = $responseValue === '' ? null : $this->required($request, 'response', 4000);
        $revision = $this->revision($request, 'resolve Attention for');
        $attempt = filter_var($request->body['attempt'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($attempt)) {
            throw new InvalidArgumentException('The reviewed execution attempt is missing; reload the page.');
        }
        // A null HEAD (no Git checkout) is part of the reviewed identity too;
        // agent-loop compares it with the current HEAD under its own lock.
        $head = $this->reviewedHead($request);

        $this->decisions->resolveAttention($taskId, $attentionId, $actor, $response, [
            'contract_revision' => $revision,
            'execution_plan_digest' => $this->required($request, 'execution_plan_digest', 80),
            'attempt' => $attempt,
            'candidate_revision' => $this->required($request, 'candidate_revision', 200),
            'head_commit' => $head,
        ]);

        return $response === null
            ? sprintf('agent-loop recorded the Attention resolution for %s (%s) by %s.', $taskId, $attentionId, $actor)
            : sprintf('agent-loop recorded the Attention resolution for %s (%s) by %s with a response for the resumed stage.', $taskId, $attentionId, $actor);
    }

    private function returnPath(string $taskId, Request $request): string
    {
        $returnTo = trim($request->body['return_to'] ?? '');
        if ($returnTo === '') {
            return '/task/' . rawurlencode($taskId);
        }
        if ($returnTo === 'progress') {
            return '/task/' . rawurlencode($taskId) . '/progress';
        }

        throw new InvalidArgumentException('Unsupported human-decision return target.');
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
