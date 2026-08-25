<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;

final class ContextExplanationGatewayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-context-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/recall/TEST-1', 0o775, true)
            && !is_dir($this->root . '/.agent-loop/recall/TEST-1')) {
            throw new RuntimeException('Unable to create context fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReadsExactPersistedExplanationWithoutRecompiling(): void
    {
        $this->writeFixture();

        $snapshot = (new ContextExplanationGateway($this->root))->task('TEST-1');

        self::assertSame(ContextExplanationSnapshot::AVAILABLE, $snapshot->status);
        self::assertNotNull($snapshot->explanation);
        self::assertSame('compile.TEST-1', $snapshot->explanation->compilationId);
        self::assertSame('constraint.no-unsafe-write', $snapshot->explanation->constraints[0]->id);
        self::assertSame(1, $snapshot->selectedGuidanceCount());
        self::assertSame(1, $snapshot->excludedGuidanceCount());
        self::assertSame('bounded context budget', $snapshot->explanation->items[0]->whyNot);
    }

    public function testMissingPersistedContextIsNotPresentedAsEmptyContext(): void
    {
        $snapshot = (new ContextExplanationGateway($this->root))->task('TEST-1');

        self::assertSame(ContextExplanationSnapshot::MISSING, $snapshot->status);
        self::assertNull($snapshot->explanation);
        self::assertNotNull($snapshot->problem);
    }

    public function testBrokenPersistedExplanationFailsToSafeInvalidProjection(): void
    {
        $this->writeFixture();
        unlink($this->root . '/.agent-loop/recall/TEST-1/selection-report.json');

        $snapshot = (new ContextExplanationGateway($this->root))->task('TEST-1');

        self::assertSame(ContextExplanationSnapshot::INVALID, $snapshot->status);
        self::assertNull($snapshot->explanation);
        self::assertStringNotContainsString($this->root, $snapshot->problem ?? '');
    }

    private function writeFixture(): void
    {
        $directory = $this->root . '/.agent-loop/recall/TEST-1';
        $bundle = [
            'schema_version' => '1.0',
            'task' => ['id' => 'TEST-1', 'revision' => 2],
            'outcome_stats' => [
                'guidance.selected' => [
                    'selected_count' => 3,
                    'helpful_count' => 2,
                    'irrelevant_count' => 1,
                    'harmful_count' => 0,
                    'violation_detected_count' => 0,
                ],
            ],
        ];
        $bundleSha256 = CanonicalJson::digest($bundle);
        $selection = [
            'schema_version' => '1.0',
            'bundle_sha256' => $bundleSha256,
            'selected_constraints' => [[
                'id' => 'constraint.no-unsafe-write',
                'engine' => 'phpstan',
                'rule_identifier' => 'NoUnsafeWriteRule',
                'source_proposal' => 'proposal.1',
            ]],
            'evaluated_guidance' => [
                [
                    'guidance_id' => 'guidance.selected',
                    'guidance_type' => 'skill',
                    'eligible' => true,
                    'selected' => true,
                    'selection_reason' => 'scope_overlap',
                    'exclusion_reason' => null,
                    'task_files' => ['src/Foo.php'],
                    'source_proposal' => 'proposal.2',
                ],
                [
                    'guidance_id' => 'guidance.excluded',
                    'guidance_type' => 'memory',
                    'eligible' => false,
                    'selected' => false,
                    'selection_reason' => null,
                    'exclusion_reason' => 'no_scope_overlap',
                    'task_files' => ['src/Foo.php'],
                ],
            ],
            'warnings' => [],
            'context_explain' => [[
                'id' => 'map-omitted:1',
                'kind' => 'map_omission',
                'what' => 'symbol:Foo::bar',
                'why' => 'The candidate was considered while constructing bounded edit context.',
                'how' => 'agent-map omission evidence.',
                'authority' => 'derived_navigation',
                'use' => 'investigate_if_relevant',
                'state' => 'unknown',
                'selected' => false,
                'source_ref' => 'map.json',
                'evidence_ids' => [],
                'why_not' => 'bounded context budget',
            ]],
        ];

        $bundleJson = CanonicalJson::pretty($bundle);
        $selectionJson = CanonicalJson::pretty($selection);
        file_put_contents($directory . '/recall.bundle.json', $bundleJson);
        file_put_contents($directory . '/selection-report.json', $selectionJson);
        file_put_contents($directory . '/meta.json', CanonicalJson::pretty([
            'schema_version' => '1.0',
            'task_id' => 'TEST-1',
            'compilation_id' => 'compile.TEST-1',
            'bundle_sha256' => $bundleSha256,
            'output_hashes' => [
                'recall.bundle.json' => hash('sha256', $bundleJson),
                'selection-report.json' => hash('sha256', $selectionJson),
            ],
        ]));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
