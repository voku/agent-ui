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

final class CodeSearchPageTest extends TestCase
{
    private MapFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new MapFixture('agent_ui_search_page_test_');
        $this->writeRepository();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testTheSearchPageWorksWithoutJavaScript(): void
    {
        $body = $this->action()->index(new Request('GET', '/map'))->body;

        // A GET form and links only: nothing on this page waits for a script to
        // become useful, which is the whole point of the server-rendered rule.
        self::assertStringContainsString('<form class="form" method="get" action="/map">', $body);
        self::assertStringContainsString('Search Index Readiness', $body);
        self::assertStringNotContainsString('<script', substr($body, 0, (int) strpos($body, '</main>')));
    }

    public function testAMissingDerivedIndexIsExplainedRatherThanLookingLikeNoCode(): void
    {
        $body = $this->action()->index(new Request('GET', '/map', query: ['q' => 'Greeter']))->body;

        self::assertStringContainsString('Result Provenance', $body);
        self::assertStringContainsString('search_index_unavailable', $body);
        self::assertStringContainsString('agent-map search-index build', $body);
        self::assertStringContainsString('App\\Greeter', $body);
    }

    public function testPreviewToggleIsHonouredInBothDirections(): void
    {
        $action = $this->action();

        $withPreview = $action->index(new Request('GET', '/map', query: ['q' => 'Greeter', 'preview' => '1']))->body;
        self::assertStringContainsString('<ol class="source__lines"', $withPreview);

        $withoutPreview = $action->index(new Request('GET', '/map', query: ['q' => 'Greeter', 'preview' => '0']))->body;
        self::assertStringNotContainsString('<ol class="source__lines"', $withoutPreview);
    }

    public function testResultLimitIsBounded(): void
    {
        $body = $this->action()->index(new Request('GET', '/map', query: ['q' => 'Greeter', 'limit' => '9999']))->body;

        self::assertStringContainsString('value="' . CodeSearchGateway::MAXIMUM_LIMIT . '"', $body);
    }

    public function testAQueryWithNoMatchSaysSoWithoutClaimingTheCodeIsAbsent(): void
    {
        $body = $this->action()->index(new Request('GET', '/map', query: ['q' => 'zzzznotasymbol']))->body;

        self::assertStringContainsString('No code matched', $body);
        self::assertStringContainsString('not proof the code does not exist', $body);
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

    private function writeRepository(): void
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            final class Greeter
            {
                public function greet(string $name): string
                {
                    return 'hello ' . $name;
                }
            }

            PHP;

        $sha = $this->fixture->writeFile('src/Greeter.php', $source);
        $this->fixture->writeMap([
            [
                'path' => 'src/Greeter.php',
                'sha256' => $sha,
                'namespace' => 'App',
                'symbols' => [
                    MapFixture::classSymbol('Greeter', 'App\\Greeter', 5, 11, [
                        MapFixture::method('greet', 7, 10),
                    ]),
                ],
            ],
        ]);
    }
}
