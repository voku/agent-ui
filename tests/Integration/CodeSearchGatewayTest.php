<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentMap\Search\CodeChunk;
use voku\AgentMap\Search\Embedding\CorpusEmbeddingProvider;
use voku\AgentMap\Search\SearchIndexStore;
use voku\AgentUi\Integration\AgentMap\CodeSearchGateway;
use voku\AgentUi\Integration\AgentMap\CodeSearchResult;

final class CodeSearchGatewayTest extends TestCase
{
    private MapFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new MapFixture('agent_ui_search_test_');
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testReadinessReportsAMissingDerivedIndexAsMissing(): void
    {
        $this->writeIndexedFile();

        $readiness = (new CodeSearchGateway($this->fixture->root))->readiness();

        self::assertSame('missing', $readiness->status);
        self::assertFalse($readiness->databaseExists);
        self::assertFalse($readiness->isUsable());
        self::assertStringEndsWith('/.agent-map/search.sqlite', $readiness->databasePath);
    }

    public function testSearchFallsBackToTheStructuralChannelWithoutADerivedIndex(): void
    {
        $this->writeIndexedFile();

        $result = (new CodeSearchGateway($this->fixture->root))->search('Greeter');

        self::assertSame('map_query', $result->mode);
        self::assertSame('map_query', $result->effectiveMode);
        self::assertTrue($result->degraded);
        self::assertSame('search_index_unavailable', $result->degradedReason);
        self::assertNotSame([], $result->hits);
        self::assertSame('App\\Greeter', $result->hits[0]->symbolName);

        // The fallback is the consumer's composition of agent-map's canonical query,
        // so it must not present itself with the owner's channel vocabulary.
        self::assertFalse($result->isOwnerReported());
        self::assertSame(CodeSearchResult::PROVENANCE_CONSUMER, $result->provenance);
        self::assertSame([], $result->structuralTerms);
        self::assertNull($result->searchIndexSnapshot);
        self::assertSame([], $result->hits[0]->reasons);
        self::assertSame(0.0, $result->hits[0]->score);
        self::assertSame(
            ['structural' => null, 'lexical' => null, 'semantic' => null],
            $result->hits[0]->channelRanks,
        );
    }

    public function testHybridResultsAreMarkedAsOwnerReported(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5.');
        }

        $sha = $this->writeIndexedFile();
        $this->writeSearchIndex($sha);

        $result = (new CodeSearchGateway($this->fixture->root))->search('cordially');

        self::assertTrue($result->isOwnerReported());
        self::assertSame(CodeSearchResult::PROVENANCE_OWNER, $result->provenance);
    }

    public function testSearchWithoutAMapIsReportedAsUnavailableRatherThanEmpty(): void
    {
        $result = (new CodeSearchGateway($this->fixture->root))->search('Greeter');

        self::assertSame('none', $result->mode);
        self::assertTrue($result->isEmpty());
        self::assertStringContainsString('agent-map build', (string) $result->failure);
    }

    public function testAnEmptyQueryAsksNothingAndClaimsNothing(): void
    {
        $this->writeIndexedFile();

        $result = (new CodeSearchGateway($this->fixture->root))->search('   ');

        self::assertSame('', $result->query);
        self::assertSame('none', $result->effectiveMode);
        self::assertFalse($result->degraded);
        self::assertTrue($result->isEmpty());
    }

    public function testHybridSearchAnswersFromTheDerivedIndexAndKeepsChannelProvenance(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5.');
        }

        $sha = $this->writeIndexedFile();
        $this->writeSearchIndex($sha);

        $result = (new CodeSearchGateway($this->fixture->root))->search('cordially');

        self::assertSame('hybrid', $result->mode);
        self::assertSame('structural+lexical', $result->effectiveMode);
        self::assertTrue($result->degraded);
        self::assertSame('semantic_channel_unavailable', $result->degradedReason);
        self::assertNotSame([], $result->hits);

        $hit = $result->hits[0];
        self::assertSame('src/Greeter.php', $hit->file);
        self::assertSame('method', $hit->kind);
        self::assertSame('App\\Greeter::greet', $hit->symbolName);
        self::assertNotNull($hit->channelRanks['lexical']);
        self::assertNotSame([], $hit->reasons);
        self::assertGreaterThan(0.0, $hit->score);
    }

    public function testHybridSearchRestoresSemanticProviderWhenEmbeddingStateMatches(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5.');
        }

        $sha = $this->writeIndexedFile();
        $this->writeSearchIndex($sha);

        $store = new SearchIndexStore($this->fixture->root . '/.agent-map/search.sqlite');
        if (!$store->enableVectorSupport()) {
            self::markTestSkipped('sqlite-vec is unavailable in this runtime.');
        }

        $chunks = $store->chunkContentsForPaths(['src/Greeter.php']);
        $provider = new CorpusEmbeddingProvider();
        /** @var list<string> $contents */
        $contents = array_column($chunks, 'content');
        $provider->fit($contents);
        $vectors = $provider->embedDocuments($contents);
        $store->prepareVectorTable($provider->model());
        $store->storeEmbeddingState($provider);
        $store->storeVectors([$chunks[0]['chunk_id'] => $vectors[0]], $provider->model());

        $result = (new CodeSearchGateway($this->fixture->root))->search('cordially');

        self::assertSame('hybrid', $result->mode);
        self::assertSame('structural+lexical+semantic', $result->effectiveMode);
        self::assertFalse($result->degraded);
        self::assertNull($result->degradedReason);
        self::assertNotSame([], $result->hits);
    }

    public function testHybridHitsCanCarryVerifiedSourcePreviews(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5.');
        }

        $sha = $this->writeIndexedFile();
        $this->writeSearchIndex($sha);

        $result = (new CodeSearchGateway($this->fixture->root))->search('cordially', 10, 1);

        self::assertNotSame([], $result->hits);
        $preview = $result->hits[0]->preview;
        self::assertNotNull($preview);
        self::assertSame('ready', $preview->status);
        self::assertNotSame([], $preview->lines);
    }

    public function testASearchIndexBehindTheMapIsReportedAsStaleAndStillAnswers(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('This PHP build has no SQLite FTS5.');
        }

        $sha = $this->writeIndexedFile('sha256:map-digest-one');
        $this->writeSearchIndex($sha, 'sha256:map-digest-zero');

        $gateway = new CodeSearchGateway($this->fixture->root);

        $readiness = $gateway->readiness();
        self::assertSame('stale', $readiness->status);
        self::assertTrue($readiness->isUsable());
        self::assertFalse($readiness->snapshotsAgree());

        // Stale is not broken: the developer is told the index is behind, and
        // still gets the answer the index can give.
        $result = $gateway->search('cordially');
        self::assertSame('hybrid', $result->mode);
        self::assertFalse($result->snapshotsAgree());
        self::assertNotSame([], $result->hits);
    }

    private function writeIndexedFile(?string $sourceDigest = null): string
    {
        $source = <<<'PHP'
            <?php

            namespace App;

            final class Greeter
            {
                public function greet(string $name): string
                {
                    // Greet the caller cordially.
                    return 'hello ' . $name;
                }
            }

            PHP;

        $sha = $this->fixture->writeFile('src/Greeter.php', $source);
        $this->fixture->writeMap(
            [
                [
                    'path' => 'src/Greeter.php',
                    'sha256' => $sha,
                    'namespace' => 'App',
                    'symbols' => [
                        MapFixture::classSymbol('Greeter', 'App\\Greeter', 5, 12, [
                            MapFixture::method('greet', 7, 11),
                        ]),
                    ],
                ],
            ],
            [],
            $sourceDigest,
        );

        return $sha;
    }

    private function writeSearchIndex(string $sourceSha, ?string $mapSnapshot = null): void
    {
        $store = new SearchIndexStore($this->fixture->root . '/.agent-map/search.sqlite');
        $store->replaceChunks([
            CodeChunk::create(
                symbolId: 'method:App\\Greeter::greet',
                kind: CodeChunk::KIND_METHOD_BODY,
                filePath: 'src/Greeter.php',
                symbolName: 'App\\Greeter::greet',
                startLine: 7,
                endLine: 11,
                sourceSha256: $sourceSha,
                signature: 'greet(string $name): string',
                content: "public function greet(string \$name): string\n{\n    // Greet the caller cordially.\n    return 'hello ' . \$name;\n}\n",
            ),
        ]);
        if ($mapSnapshot !== null) {
            $store->setMeta('map_snapshot', $mapSnapshot);
        }
    }
}
