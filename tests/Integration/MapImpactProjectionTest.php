<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Map\MapAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentMap\CodeSearchGateway;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\Integration\AgentMap\SourceViewGateway;
use voku\AgentUi\View\TemplateRenderer;

final class MapImpactProjectionTest extends TestCase
{
    private MapFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new MapFixture('agent_ui_impact_test_');
        $this->writeMap();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testImpactWalksDependencyEdgesBackwardsAndKeepsUncertainty(): void
    {
        $impact = (new MapProjectionGateway($this->fixture->root))
            ->impact('method:App\\Greeter::greet', 2, 50);

        self::assertNotNull($impact);
        self::assertSame('method:App\\Greeter::greet', $impact->targetId);
        self::assertSame('App\\Greeter::greet', $impact->targetName);
        self::assertFalse($impact->truncated);

        $byId = [];
        foreach ($impact->impacts as $node) {
            $byId[$node->id] = $node;
        }

        self::assertArrayHasKey('method:App\\Controller::index', $byId);
        self::assertSame(1, $byId['method:App\\Controller::index']->depth);
        self::assertFalse($byId['method:App\\Controller::index']->uncertain);
        self::assertSame(['calls'], $byId['method:App\\Controller::index']->relationKinds);

        // Reached only through a dynamic call, so the path stays marked uncertain
        // instead of being presented as a resolved dependency.
        self::assertArrayHasKey('method:App\\Kernel::boot', $byId);
        self::assertSame(2, $byId['method:App\\Kernel::boot']->depth);
        self::assertTrue($byId['method:App\\Kernel::boot']->uncertain);
        self::assertSame(1, $impact->uncertainCount());
    }

    public function testDepthBoundsTheTraversal(): void
    {
        $impact = (new MapProjectionGateway($this->fixture->root))
            ->impact('method:App\\Greeter::greet', 1, 50);

        self::assertNotNull($impact);
        self::assertCount(1, $impact->impacts);
        self::assertSame(1, $impact->maximumDepth);
    }

    public function testNodeBoundIsReportedAsTruncationRatherThanSilentlyDropped(): void
    {
        $impact = (new MapProjectionGateway($this->fixture->root))
            ->impact('method:App\\Greeter::greet', 2, 1);

        self::assertNotNull($impact);
        self::assertTrue($impact->truncated);
        self::assertCount(1, $impact->impacts);
    }

    public function testAnUnknownTargetIsNotApproximated(): void
    {
        $gateway = new MapProjectionGateway($this->fixture->root);

        self::assertNull($gateway->impact('method:App\\Nowhere::atAll'));
    }

    public function testImpactPageDrawsTheRingsAndListsTheSameFacts(): void
    {
        $response = $this->action()->impact(new Request('GET', '/map/impact', query: [
            'target' => 'method:App\\Greeter::greet',
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<svg', $response->body);
        self::assertStringContainsString('class="impact__target"', $response->body);
        self::assertStringContainsString('class="impact__edge impact__edge--uncertain"', $response->body);
        // The picture is a shortcut to the evidence, never a replacement: the
        // same nodes are listed as text with their relation kinds.
        self::assertStringContainsString('Depth 1 (1)', $response->body);
        self::assertStringContainsString('Depth 2 (1)', $response->body);
        self::assertStringContainsString('uncertain path', $response->body);
        self::assertStringContainsString('App\\Kernel::boot', $response->body);
    }

    public function testSourcePageRendersVerifiedLinesForAnIndexedFile(): void
    {
        $this->fixture->writeFile('src/Greeter.php', "<?php\n\nnamespace App;\n");
        $sha = 'sha256:' . hash('sha256', "<?php\n\nnamespace App;\n");
        $this->writeMap($sha);

        $response = $this->action()->sourceView(new Request('GET', '/map/source', query: [
            'path' => 'src/Greeter.php',
            'line' => '3',
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<ol class="source__lines"', $response->body);
        self::assertStringContainsString('class="source__line source__line--focus"', $response->body);
        self::assertStringContainsString('namespace App;', $response->body);
    }

    public function testSourcePageReportsAMissingMapInsteadOfShowingSource(): void
    {
        $response = $this->action()->sourceView(new Request('GET', '/map/source', query: [
            'path' => 'src/Absent.php',
        ]));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Not in the map', $response->body);
        self::assertStringNotContainsString('<ol class="source__lines"', $response->body);
    }

    private function action(): MapAction
    {
        $map = new MapProjectionGateway($this->fixture->root);
        $source = new SourceViewGateway($this->fixture->root);

        return new MapAction(
            $map,
            new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
            new CodeSearchGateway($this->fixture->root, null, $map, $source),
            $source,
        );
    }

    private function writeMap(string $greeterSha = 'sha256:unverified'): void
    {
        $this->fixture->writeMap(
            [
                [
                    'path' => 'src/Greeter.php',
                    'sha256' => $greeterSha,
                    'namespace' => 'App',
                    'symbols' => [
                        MapFixture::classSymbol('Greeter', 'App\\Greeter', 5, 12, [
                            MapFixture::method('greet', 7, 11),
                        ]),
                    ],
                ],
                [
                    'path' => 'src/Controller.php',
                    'sha256' => 'sha256:unverified',
                    'namespace' => 'App',
                    'symbols' => [
                        MapFixture::classSymbol('Controller', 'App\\Controller', 5, 20, [
                            MapFixture::method('index', 7, 12),
                        ]),
                    ],
                ],
                [
                    'path' => 'src/Kernel.php',
                    'sha256' => 'sha256:unverified',
                    'namespace' => 'App',
                    'symbols' => [
                        MapFixture::classSymbol('Kernel', 'App\\Kernel', 5, 20, [
                            MapFixture::method('boot', 7, 12),
                        ]),
                    ],
                ],
            ],
            [
                [
                    'id' => 'relation:controller-calls-greeter',
                    'source_id' => 'method:App\\Controller::index',
                    'kind' => 'calls',
                    'target_ids' => ['method:App\\Greeter::greet'],
                    'file' => 'src/Controller.php',
                    'line_start' => 9,
                    'line_end' => 9,
                    'resolution' => 'static',
                ],
                [
                    'id' => 'relation:kernel-calls-controller',
                    'source_id' => 'method:App\\Kernel::boot',
                    'kind' => 'calls',
                    'target_ids' => ['method:App\\Controller::index'],
                    'file' => 'src/Kernel.php',
                    'line_start' => 9,
                    'line_end' => 9,
                    'resolution' => 'dynamic',
                ],
            ],
        );
    }
}
