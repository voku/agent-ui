<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Map;

use InvalidArgumentException;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentMap\CodeSearchGateway;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\Integration\AgentMap\SourceViewGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class MapAction
{
    /** How many lines of context a search hit carries when previews are on. */
    private const int PREVIEW_CONTEXT_LINES = 2;

    public function __construct(
        private MapProjectionGateway $map,
        private TemplateRenderer $templates,
        private CodeSearchGateway $search,
        private SourceViewGateway $source,
    ) {
    }

    public function index(Request $request): Response
    {
        $readiness = $this->map->readiness();
        $searchReadiness = $this->search->readiness();
        $query = trim($request->query['q'] ?? '');
        $limit = $this->boundedInt($request->query['limit'] ?? null, CodeSearchGateway::DEFAULT_LIMIT, 5, CodeSearchGateway::MAXIMUM_LIMIT);
        $withPreviews = ($request->query['preview'] ?? '1') !== '0';

        $result = $query === ''
            ? null
            : $this->search->search($query, $limit, $withPreviews ? self::PREVIEW_CONTEXT_LINES : 0);

        return Response::html($this->templates->render('map/index', [
            'readiness' => $readiness,
            'searchReadiness' => $searchReadiness,
            'query' => $query,
            'limit' => $limit,
            'withPreviews' => $withPreviews,
            'result' => $result,
        ]));
    }

    public function graph(Request $request): Response
    {
        $readiness = $this->map->readiness();
        $region = trim($request->query['region'] ?? '');
        $maximumNodes = isset($request->query['nodes']) ? max(2, min(100, (int) $request->query['nodes'])) : 30;
        $maximumEdges = isset($request->query['edges']) ? max(1, min(200, (int) $request->query['edges'])) : 80;
        $graph = $readiness->isUsable()
            ? $this->map->graph($region !== '' ? $region : null, $maximumNodes, $maximumEdges)
            : null;

        return Response::html($this->templates->render('map/graph', [
            'readiness' => $readiness,
            'graph' => $graph,
        ]));
    }

    public function symbol(Request $request): Response
    {
        $id = trim($request->query['id'] ?? '');
        if ($id === '') {
            return Response::redirect('/map');
        }

        $symbol = $this->map->symbol($id);
        if ($symbol === null) {
            throw new InvalidArgumentException(sprintf('Symbol "%s" not found in the code map.', $id));
        }

        return Response::html($this->templates->render('map/symbol', [
            'symbol' => $symbol,
            'source' => $this->source->slice(
                $symbol->file,
                $symbol->lineStart,
                $symbol->lineEnd,
                0,
                SourceViewGateway::MAXIMUM_PREVIEW_LINES,
            ),
        ]));
    }

    public function context(Request $request): Response
    {
        $target = trim($request->query['target'] ?? '');
        if ($target === '') {
            return Response::redirect('/map');
        }

        $plan = $this->map->editContext($target);
        if ($plan === null) {
            $readiness = $this->map->readiness();
            if ($readiness->status === 'stale') {
                throw new InvalidArgumentException(sprintf(
                    'The code map is stale (%d changed file(s)). Run "vendor/bin/agent-map refresh" before generating an edit context plan.',
                    count($readiness->staleEntries),
                ));
            }
            if ($readiness->status === 'missing') {
                throw new InvalidArgumentException('No code map index found. Run "vendor/bin/agent-map build" first.');
            }

            throw new InvalidArgumentException(sprintf('Could not generate edit context plan for target "%s". Target must use Class::method syntax and exist in the map.', $target));
        }

        return Response::html($this->templates->render('map/context', [
            'target' => $target,
            'plan' => $plan,
        ]));
    }

    /** Bounded source reading, anchored on the map that indexed the file. */
    public function sourceView(Request $request): Response
    {
        $path = trim($request->query['path'] ?? '');
        if ($path === '') {
            return Response::redirect('/map');
        }

        $line = isset($request->query['line']) ? max(1, (int) $request->query['line']) : null;
        $view = $this->source->file($path, $line, SourceViewGateway::MAXIMUM_FILE_LINES);

        return Response::html($this->templates->render('map/source', [
            'view' => $view,
            'path' => $path,
            'line' => $line,
        ]));
    }

    /** The reverse-dependency picture for one symbol: what can notice a change here. */
    public function impact(Request $request): Response
    {
        $target = trim($request->query['target'] ?? '');
        if ($target === '') {
            return Response::redirect('/map');
        }

        $depth = $this->boundedInt($request->query['depth'] ?? null, 2, 1, 4);
        $nodes = $this->boundedInt($request->query['nodes'] ?? null, 60, 5, 200);
        $impact = $this->map->impact($target, $depth, $nodes);
        if ($impact === null) {
            $readiness = $this->map->readiness();
            if ($readiness->status === 'missing') {
                throw new InvalidArgumentException('No code map index found. Run "vendor/bin/agent-map build" first.');
            }

            throw new InvalidArgumentException(sprintf(
                'agent-map does not know an indexed node for target "%s". Use a symbol id from the map, or Class::method syntax.',
                $target,
            ));
        }

        return Response::html($this->templates->render('map/impact', [
            'impact' => $impact,
            'target' => $target,
            'depth' => $depth,
            'nodes' => $nodes,
        ]));
    }

    private function boundedInt(?string $raw, int $default, int $minimum, int $maximum): int
    {
        if ($raw === null || trim($raw) === '') {
            return $default;
        }

        return max($minimum, min($maximum, (int) $raw));
    }
}
