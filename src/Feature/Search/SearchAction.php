<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Search;

use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\View\TemplateRenderer;

/**
 * The one search page. Read-only and GET-only: a search changes nothing, so it
 * needs no CSRF token and is safe to bookmark, share and reach with the back
 * button - the query is the URL.
 */
final readonly class SearchAction
{
    public function __construct(
        private GlobalSearch $search,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $query = SearchQuery::parse($request->query['q'] ?? '');

        return Response::html($this->templates->render('search/index', [
            'query' => $query,
            'results' => $this->search->search($query),
        ]));
    }
}
