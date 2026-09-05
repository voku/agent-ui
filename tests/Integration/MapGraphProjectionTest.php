<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Map\MapAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
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
        $action = new MapAction(new MapProjectionGateway($this->root), $templates);

        $response = $action->graph(new Request('GET', '/map/graph'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<svg', $response->body);
        self::assertStringContainsString('Edges &amp; evidence', $response->body);
        self::assertStringContainsString('agent-map discovery projection', $response->body);
        self::assertStringContainsString('references_type', $response->body);
        self::assertStringContainsString('class="graph-edge"', $response->body);
        self::assertStringContainsString('class="graph-node-link"', $response->body);
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
        $action = new MapAction($gateway, $templates);
        $response = $action->graph(new Request('GET', '/map/graph', query: ['region' => 'nonexistent-xyz']));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<svg', $response->body);
    }

    public function testGraphActionSupportsCustomNodeAndEdgeBoundsFromQuery(): void
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $action = new MapAction(new MapProjectionGateway($this->root), $templates);

        $response = $action->graph(new Request('GET', '/map/graph', query: ['nodes' => '2', 'edges' => '1']));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<svg', $response->body);
    }

    private function writeMap(): void
    {
        $files = [];
        foreach (['Alpha.php', 'Beta.php', 'Gamma.php'] as $name) {
            $className = substr($name, 0, -4);
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
                'target_ids' => ['class:App\\Feature\\Beta'],
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
}
