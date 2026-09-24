<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Knowledge;

use InvalidArgumentException;
use Throwable;
use voku\AgentUi\Http\FlashNotice;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\DreamGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

/**
 * Run Dream from the Knowledge page.
 *
 * Viewing the page runs agent-loop's read-only Dream preview. Writing
 * candidates is a separate POST that must be CSRF-valid and explicitly
 * confirmed; it re-runs Dream through the owner rather than trusting the
 * decisions the browser last saw, so a stale page cannot write stale
 * candidates.
 */
final readonly class DreamAction
{
    public function __construct(
        private DreamGateway $dream,
        private CsrfTokenManager $csrf,
        private TemplateRenderer $templates,
        private FlashNotice $notice = new FlashNotice(),
    ) {
    }

    public function preview(): Response
    {
        $report = null;
        $problem = null;
        try {
            $report = $this->dream->preview();
        } catch (Throwable $exception) {
            $problem = $exception->getMessage();
        }

        return Response::html($this->templates->render('knowledge/dream', [
            'report' => $report,
            'problem' => $problem,
            'csrf' => $this->csrf->token(),
        ]), $problem === null ? 200 : 503);
    }

    public function writeCandidates(Request $request): Response
    {
        $this->csrf->assertValid($request->body['_csrf'] ?? null);
        if (($request->body['confirm_write'] ?? '') !== 'write') {
            throw new InvalidArgumentException('Writing candidates was not confirmed, so nothing was written.');
        }

        $report = $this->dream->writeCandidates();
        $written = count($report->outcome->writtenCandidateIds);
        $this->notice->record($written === 0
            ? 'agent-learning wrote no candidate Proposals: every reviewable decision was already a candidate or suppressed.'
            : sprintf('agent-learning wrote %d candidate Proposal(s). They are candidates only and need review before any becomes guidance.', $written));

        return Response::redirect($written === 0 ? '/knowledge/dream' : '/knowledge?tab=proposals');
    }
}
