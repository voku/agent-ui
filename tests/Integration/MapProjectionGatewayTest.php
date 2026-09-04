<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;

final class MapProjectionGatewayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/agent_ui_map_test_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-map', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testReadinessWhenNoIndexExists(): void
    {
        $gateway = new MapProjectionGateway($this->root);
        $readiness = $gateway->readiness();

        self::assertSame('missing', $readiness->status);
        self::assertFalse($readiness->isReady());
        self::assertSame(0, $readiness->fileCount);
        self::assertSame(0, $readiness->symbolCount);
    }

    public function testReadinessAndSearchWithValidMap(): void
    {
        $mapJson = json_encode([
            'schema_version' => '2.0',
            'root' => $this->root,
            'backend' => 'simple-php-code-parser+phpstan',
            'files' => [
                [
                    'path' => 'src/Service/OrderProcessor.php',
                    'sha256' => hash('sha256', 'dummy'),
                    'namespace' => 'App\\Service',
                    'symbols' => [
                        [
                            'kind' => 'class',
                            'name' => 'OrderProcessor',
                            'fqn' => 'App\\Service\\OrderProcessor',
                            'line_start' => 10,
                            'line_end' => 50,
                            'methods' => [
                                [
                                    'name' => 'process',
                                    'visibility' => 'public',
                                    'line_start' => 15,
                                    'line_end' => 30,
                                    'parameters' => [
                                        ['name' => 'orderId', 'native_type' => 'int', 'phpdoc_type' => null, 'resolved_type' => null, 'by_reference' => false, 'variadic' => false],
                                    ],
                                    'native_return_type' => 'bool',
                                    'phpdoc_return_type' => null,
                                    'resolved_return_type' => null,
                                    'attributes' => [],
                                    'reconciliation_status' => 'structural_only',
                                ],
                            ],
                            'extends' => [],
                            'implements' => [],
                            'parameters' => [],
                            'native_return_type' => null,
                            'phpdoc_return_type' => null,
                            'resolved_return_type' => null,
                            'attributes' => [],
                            'uses' => [],
                            'templates' => [],
                            'reconciliation_status' => 'structural_only',
                        ],
                    ],
                    'semantic_status' => 'analyzed',
                ],
            ],
            'relations' => [
                [
                    'source_id' => 'method:App\\Controller\\OrderController::checkout',
                    'kind' => 'calls',
                    'target_ids' => ['method:App\\Service\\OrderProcessor::process'],
                    'file' => 'src/Controller/OrderController.php',
                    'line_start' => 25,
                    'line_end' => 25,
                    'resolution' => 'definite',
                ],
            ],
            'diagnostics' => [],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($this->root . '/.agent-map/php-symbols.json', $mapJson);

        $gateway = new MapProjectionGateway($this->root);
        $readiness = $gateway->readiness();

        self::assertSame('simple-php-code-parser+phpstan', $readiness->backend);
        self::assertSame(1, $readiness->fileCount);
        self::assertSame(1, $readiness->symbolCount);
        self::assertSame(1, $readiness->classCount);
        self::assertSame(1, $readiness->methodCount);
        self::assertSame(1, $readiness->relationCount);

        // Search
        $matches = $gateway->search('OrderProcessor');
        self::assertCount(1, $matches);
        self::assertSame('App\\Service\\OrderProcessor', $matches[0]->fqn);
        self::assertSame(1, $matches[0]->methodCount);

        // Symbol detail
        $detail = $gateway->symbol('class:App\\Service\\OrderProcessor');
        self::assertNotNull($detail);
        self::assertSame('OrderProcessor', $detail->name);
        self::assertCount(1, $detail->methods);
        self::assertSame('process', $detail->methods[0]['name']);

        // Method detail with callers
        $methodDetail = $gateway->symbol('method:App\\Service\\OrderProcessor::process');
        self::assertNotNull($methodDetail);
        self::assertSame('process', $methodDetail->name);
        self::assertCount(1, $methodDetail->callers);
        self::assertSame('method:App\\Controller\\OrderController::checkout', $methodDetail->callers[0]['sourceId']);
    }

    public function testApplicationServesMapDashboardAndSearch(): void
    {
        $app = new Application(
            dirname(__DIR__, 2), // real project root has src/ templates/
            dirname(__DIR__, 2) . '/templates',
        );

        $response = $app->handle(new Request('GET', '/map'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Code Map &amp; Architecture', $response->body);
        self::assertStringContainsString('Map Readiness', $response->body);

        $searchResponse = $app->handle(new Request('GET', '/map', query: ['q' => 'Router']));
        self::assertSame(200, $searchResponse->status);
        self::assertStringContainsString('Search Results for', $searchResponse->body);
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
