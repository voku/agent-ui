<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Map;

use InvalidArgumentException;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class MapAction
{
    public function __construct(
        private MapProjectionGateway $map,
        private TemplateRenderer $templates,
    ) {
    }

    public function index(Request $request): Response
    {
        $readiness = $this->map->readiness();
        $query = trim($request->query['q'] ?? '');
        $results = $query !== '' ? $this->map->search($query) : [];

        return Response::html($this->templates->render('map/index', [
            'readiness' => $readiness,
            'query' => $query,
            'results' => $results,
        ]));
    }

    public function graph(Request $request): Response
    {
        $readiness = $this->map->readiness();
        $region = trim($request->query['region'] ?? '');
        $graph = $readiness->isUsable()
            ? $this->map->graph($region !== '' ? $region : null)
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
}
