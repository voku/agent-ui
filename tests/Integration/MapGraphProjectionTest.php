<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Map\MapAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentMap\CodeSearchGateway;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\Integration\AgentMap\SourceViewGateway;
use voku\AgentUi\View\ClientScript;
use voku\AgentUi\View\TemplateRenderer;

final class MapGraphProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/agent_ui_graph_test_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-map', 0o775, true);
        $this->writeMap();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testGraphProjectionIsBoundedAndCarriesOwnerSignals(): void
    {
        $graph = (new MapProjectionGateway($this->root))->graph(null, 2, 1);

        self::assertNotNull($graph);
        self::assertSame('files', $graph->scope);
        self::assertSame(3, $graph->totalNodeCount);
        self::assertCount(2, $graph->nodes);
        self::assertSame(3, $graph->totalEdgeCount);
        self::assertCount(1, $graph->edges);
        self::assertArrayHasKey('references_type', $graph->edges[0]->signals);
        self::assertArrayHasKey('path', $graph->edges[0]->signals);
        self::assertTrue($graph->isTruncated());
    }

    public function testGraphActionRendersSvgAndTextFallback(): void
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $action = $this->action(new MapProjectionGateway($this->root), $templates);

        $response = $action->graph(new Request('GET', '/map/graph'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<svg', $response->body);
        self::assertStringContainsString('Edges &amp; evidence', $response->body);
        self::assertStringContainsString('agent-map discovery projection', $response->body);
        self::assertStringContainsString('references_type', $response->body);
        self::assertStringContainsString('class="graph-edge"', $response->body);
        self::assertStringContainsString('class="graph-node-link"', $response->body);
    }

    public function testTheInteractiveExplorerIsDrivenByTheSameSnapshotAsTheStaticView(): void
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $gateway = new MapProjectionGateway($this->root);
        $graph = $gateway->graph(null, 30, 80);
        self::assertNotNull($graph);

        $body = $this->action($gateway, $templates)->graph(new Request('GET', '/map/graph'))->body;

        // The interactive layer is a view over the rendered snapshot: every
        // node it can focus is a node the static drawing and the tables show.
        foreach ($graph->nodes as $node) {
            self::assertStringContainsString('data-node-id="' . $node->id . '"', $body);
            self::assertStringContainsString('data-graph-detail="' . $node->id . '"', $body);
        }

        // Focus is neighbourhood evidence the server derived from the same
        // edges, not an adjacency the browser reconstructs.
        $neighbours = [];
        foreach ($graph->edges as $edge) {
            $neighbours[$edge->sourceId][$edge->targetId] = true;
            $neighbours[$edge->targetId][$edge->sourceId] = true;
        }
        self::assertNotSame([], $neighbours);

        // Read the attributes back off the element that carries them: a
        // neighbour set that is present somewhere in the page proves nothing
        // about which node it belongs to.
        $rendered = [];
        preg_match_all(
            '/data-node-id="([^"]*)"\s+data-neighbours="([^"]*)"/',
            $body,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            $rendered[html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        }
        self::assertCount(count($graph->nodes), $rendered, 'every node link carries both attributes');

        foreach ($graph->nodes as $node) {
            self::assertArrayHasKey($node->id, $rendered);
            // A node identity is an owner string — a file node's id is its
            // repository path — so the neighbour set travels as JSON and never
            // as a delimiter a path is allowed to contain.
            self::assertSame(
                array_keys($neighbours[$node->id] ?? []),
                json_decode($rendered[$node->id], true, 512, JSON_THROW_ON_ERROR),
                'the neighbour set on ' . $node->id . ' is the adjacency its own edges produce',
            );
        }

        self::assertStringContainsString('data-graph-viewport', $body);
        self::assertStringContainsString('data-graph-base-view="0 0 1000 700"', $body);
    }

    public function testEveryExplorerControlStaysHiddenUntilTheEnhancementScriptRevealsIt(): void
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');

        $body = $this->action(new MapProjectionGateway($this->root), $templates)->graph(new Request('GET', '/map/graph'))->body;

        // Without JavaScript the static drawing and the tables are the whole
        // answer, so no control that only the script can operate may show.
        self::assertStringContainsString('data-graph-toolbar hidden', $body);

        // Every panel, not merely one of them: a single visible detail panel
        // would put explorer chrome in front of a reader who cannot operate it.
        $panels = preg_match_all('/data-graph-detail="[^"]*"/', $body);
        $hiddenPanels = preg_match_all('/data-graph-detail="[^"]*"[^>]*\\shidden/', $body);
        self::assertGreaterThan(0, $panels);
        self::assertSame($panels, $hiddenPanels, 'every rendered detail panel ships hidden');
        self::assertStringContainsString('<svg', $body);
        self::assertStringContainsString('Edges &amp; evidence', $body);
    }

    public function testTheExplorerNeverResolvesItsOwnNavigationTargets(): void
    {
        $script = ClientScript::code();

        // Focus and zoom are representation. Anything that decides where a
        // node leads belongs to the server-rendered markup.
        self::assertStringContainsString('data-graph-viewport', $script);
        self::assertStringNotContainsString('/map/source?path=', $script);
        self::assertStringNotContainsString('/map/graph?region=', $script);
        self::assertStringNotContainsString('location.href', $script);
        self::assertStringNotContainsString('location.assign', $script);
    }

    public function testGraphProjectionSupportsQueryByFilePathAndGracefulFallback(): void
    {
        $gateway = new MapProjectionGateway($this->root);

        // Resolving by file path
        $byFile = $gateway->graph('src/Feature/Alpha.php');
        self::assertNotNull($byFile);
        self::assertNotEmpty($byFile->nodes);

        // Graceful fallback on unknown region
        $unknown = $gateway->graph('nonexistent-region-id-999');
        self::assertNotNull($unknown);
        self::assertNotEmpty($unknown->nodes);

        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $action = $this->action($gateway, $templates);
        $response = $action->graph(new Request('GET', '/map/graph', query: ['region' => 'nonexistent-xyz']));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<svg', $response->body);
    }

    public function testGraphActionSupportsCustomNodeAndEdgeBoundsFromQuery(): void
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $action = $this->action(new MapProjectionGateway($this->root), $templates);

        $response = $action->graph(new Request('GET', '/map/graph', query: ['nodes' => '2', 'edges' => '1']));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<svg', $response->body);
    }

    private function writeMap(): void
    {
        $files = [];
        // 'Old Beta.php' is deliberate: a repository path may contain a space,
        // and a file node's id is its path, so anything the page encodes per
        // node has to survive one.
        foreach (['Alpha.php', 'Old Beta.php', 'Gamma.php'] as $name) {
            $className = str_replace(' ', '', substr($name, 0, -4));
            $files[] = [
                'path' => 'src/Feature/' . $name,
                'sha256' => hash('sha256', $name),
                'namespace' => 'App\\Feature',
                'symbols' => [[
                    'kind' => 'class',
                    'name' => $className,
                    'fqn' => 'App\\Feature\\' . $className,
                    'line_start' => 1,
                    'line_end' => 20,
                    'methods' => [],
                    'extends' => [],
                    'implements' => [],
                    'parameters' => [],
                    'attributes' => [],
                    'uses' => [],
                    'templates' => [],
                    'reconciliation_status' => 'structural_only',
                ]],
                'semantic_status' => 'analyzed',
            ];
        }

        $map = [
            'schema_version' => '2.0',
            'root' => $this->root,
            'backend' => 'simple-php-code-parser+phpstan',
            'files' => $files,
            'relations' => [[
                'source_id' => 'class:App\\Feature\\Alpha',
                'kind' => 'references_type',
                'target_ids' => ['class:App\\Feature\\OldBeta'],
                'file' => 'src/Feature/Alpha.php',
                'line_start' => 8,
                'line_end' => 8,
                'resolution' => 'definite',
            ]],
            'diagnostics' => [],
        ];

        file_put_contents(
            $this->root . '/.agent-map/php-symbols.json',
            json_encode($map, JSON_THROW_ON_ERROR),
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    private function action(MapProjectionGateway $map, TemplateRenderer $templates): MapAction
    {
        $source = new SourceViewGateway($this->root);

        return new MapAction(
            $map,
            $templates,
            new CodeSearchGateway($this->root, null, $map, $source),
            $source,
        );
    }
}
