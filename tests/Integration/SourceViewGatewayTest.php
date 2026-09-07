<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Integration\AgentMap\SourceViewGateway;

final class SourceViewGatewayTest extends TestCase
{
    private MapFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new MapFixture('agent_ui_source_test_');
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testRendersVerifiedSourceWithRealLineNumbers(): void
    {
        $this->writeIndexedFile();

        $view = (new SourceViewGateway($this->fixture->root))->file('src/Greeter.php', 7);

        self::assertSame('ready', $view->status);
        self::assertTrue($view->isRendered());
        self::assertSame(1, $view->lineStart);
        self::assertSame('<?php', $view->lines[0]->text);
        self::assertSame(1, $view->lines[0]->number);
        self::assertFalse($view->hasMoreBefore);
        self::assertFalse($view->hasMoreAfter);

        $focused = array_values(array_filter($view->lines, static fn($line): bool => $line->focus));
        self::assertCount(1, $focused);
        self::assertSame(7, $focused[0]->number);
    }

    public function testStaleWorkingTreeIsReportedInsteadOfRendered(): void
    {
        $this->writeIndexedFile();
        // The map keeps the hash it recorded; the file moves on underneath it.
        file_put_contents($this->fixture->root . '/src/Greeter.php', "<?php\n// rewritten\n");

        $view = (new SourceViewGateway($this->fixture->root))->file('src/Greeter.php');

        self::assertSame('stale', $view->status);
        self::assertFalse($view->isRendered());
        self::assertSame([], $view->lines);
        self::assertNotNull($view->failure);
    }

    public function testUnindexedAndEscapingPathsAreRefused(): void
    {
        $this->writeIndexedFile();
        $gateway = new SourceViewGateway($this->fixture->root);

        self::assertSame('not_indexed', $gateway->file('src/Absent.php')->status);
        self::assertSame('not_indexed', $gateway->file('../../etc/passwd')->status);
    }

    public function testMissingMapIsNotAStaleFile(): void
    {
        $view = (new SourceViewGateway($this->fixture->root))->file('src/Greeter.php');

        self::assertSame('missing', $view->status);
        self::assertStringContainsString('agent-map build', (string) $view->failure);
    }

    public function testWindowIsBoundedAndSaysSo(): void
    {
        $this->writeIndexedFile();

        $view = (new SourceViewGateway($this->fixture->root))->file('src/Greeter.php', null, 3);

        self::assertSame('ready', $view->status);
        self::assertSame(3, $view->lineCount());
        self::assertSame(1, $view->lineStart);
        self::assertSame(3, $view->lineEnd);
        self::assertFalse($view->hasMoreBefore);
        self::assertTrue($view->hasMoreAfter);
        self::assertTrue($view->isBounded());
    }

    public function testSliceCarriesSurroundingContext(): void
    {
        $this->writeIndexedFile();

        $view = (new SourceViewGateway($this->fixture->root))->slice('src/Greeter.php', 7, 9, 2);

        self::assertSame('ready', $view->status);
        self::assertSame(5, $view->lineStart);
        self::assertTrue($view->hasMoreBefore);
    }

    public function testSymbolIndexIsOrderedByPosition(): void
    {
        $this->writeIndexedFile();

        $view = (new SourceViewGateway($this->fixture->root))->file('src/Greeter.php');

        self::assertSame(
            ['Greeter', 'Greeter::greet', 'Greeter::shout'],
            array_map(static fn(array $symbol): string => $symbol['name'], $view->symbols),
        );
    }

    private function writeIndexedFile(): void
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

                public function shout(string $name): string
                {
                    return strtoupper($this->greet($name));
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
                    MapFixture::classSymbol('Greeter', 'App\\Greeter', 5, 16, [
                        MapFixture::method('greet', 7, 10),
                        MapFixture::method('shout', 12, 15),
                    ]),
                ],
            ],
        ]);
    }
}
