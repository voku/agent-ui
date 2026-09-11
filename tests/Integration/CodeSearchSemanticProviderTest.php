<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Cli\AgentMapApplication;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentUi\Integration\AgentMap\CodeSearchGateway;

final class CodeSearchSemanticProviderTest extends TestCase
{
    private MapFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new MapFixture('agent_ui_semantic_search_test_');
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testReleasedOwnerProviderEnablesSemanticHybridSearchWhenAvailable(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5.');
        }

        $source = <<<'PHP'
            <?php

            namespace App;

            final class RetryUpload
            {
                public function attempt(): string
                {
                    // Retry the upload with exponential backoff until it succeeds.
                    return 'retried';
                }
            }

            PHP;

        $sha = $this->fixture->writeFile('src/RetryUpload.php', $source);
        $this->fixture->writeMap([
            [
                'path' => 'src/RetryUpload.php',
                'sha256' => $sha,
                'namespace' => 'App',
                'symbols' => [
                    MapFixture::classSymbol('RetryUpload', 'App\\RetryUpload', 5, 12, [
                        MapFixture::method('attempt', 7, 11),
                    ]),
                ],
            ],
        ]);

        $index = $this->fixture->root . '/.agent-map/php-symbols.json';
        $database = $this->fixture->root . '/.agent-map/search.sqlite';
        ob_start();
        try {
            $exit = (new AgentMapApplication())->run([
                'agent-map',
                'search-index',
                'build',
                '--root=' . $this->fixture->root,
                '--index=' . $index,
                '--database=' . $database,
            ]);
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit);

        $store = new SearchIndexStore($database);
        if ($store->semanticProvider() === null) {
            self::markTestSkipped('This runtime has no usable persisted semantic channel.');
        }

        $result = (new CodeSearchGateway($this->fixture->root))->search('retry the upload');

        self::assertTrue($result->isOwnerReported());
        self::assertSame('structural+lexical+semantic', $result->effectiveMode);
        self::assertFalse($result->degraded);
        self::assertNull($result->degradedReason);
        self::assertNotSame([], $result->hits);
    }
}
