<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\GitHubWorkflow;

use voku\AgentUi\Http\Response;
use voku\AgentUi\View\TemplateRenderer;

/**
 * Read-only cross-repository GitHub observation. This is NOT a Loop lifecycle
 * projection and cannot authorize, approve, release, or merge work.
 */
final readonly class GitHubWorkflowAction
{
    public function __construct(private TemplateRenderer $templates)
    {
    }

    public function __invoke(): Response
    {
        return Response::html($this->templates->render('github-workflow/index', []));
    }
}
