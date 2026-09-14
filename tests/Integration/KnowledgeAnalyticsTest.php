<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Knowledge\KnowledgeAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\View\TemplateRenderer;

final class KnowledgeAnalyticsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/agent_ui_analytics_' . bin2hex(random_bytes(6));
        $learning = $this->root . '/.agent-loop/learning';
        mkdir($learning . '/findings/validated', 0o775, true);
        mkdir($learning . '/proposals/applied', 0o775, true);
        mkdir($learning . '/proposals/retired', 0o775, true);
        mkdir($learning . '/notes/active', 0o775, true);
        mkdir($learning . '/constraints/active', 0o775, true);
        mkdir($learning . '/history', 0o775, true);

        file_put_contents(
            $learning . '/config.json',
            json_encode(['schema_version' => '1.0', 'task_id_pattern' => '^[A-Z]+-[0-9]+$'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        // Finding
        file_put_contents($learning . '/findings/validated/finding.2026-06-01.001.json', json_encode([
            'id' => 'finding.2026-06-01.001',
            'task_id' => 'UI-1',
            'session' => 'session_UI-1',
            'created_at' => '2026-06-01T10:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Test.php'],
            'observation' => 'Test observation',
            'hypothesis' => 'Test hypothesis',
            'validated_conclusion' => 'Test conclusion',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'internal',
            'evidence' => [['type' => 'manual_verification', 'summary' => 'test']],
        ], JSON_THROW_ON_ERROR));

        // Retired proposal
        file_put_contents($learning . '/proposals/retired/proposal.2026-06-05.001.json', json_encode([
            'id' => 'proposal.2026-06-05.001',
            'status' => 'retired',
            'action' => 'ADD',
            'target_type' => 'memory',
            'target' => 'MEMORY.md',
            'new' => 'Test memory rule',
            'boundary' => 'Applies to test',
            'created_at' => '2026-06-05T10:00:00+00:00',
            'proposed_by' => 'test',
            'approved_by' => 'reviewer',
            'approved_at' => '2026-06-06T10:00:00+00:00',
            'scope' => ['src/Test.php'],
            'validation' => ['make test'],
            'source_findings' => ['finding.2026-06-01.001'],
            'reason' => 'Captured in target canonical guidance; confirmed landed.',
        ], JSON_THROW_ON_ERROR));

        // Approved proposal
        mkdir($learning . '/proposals/approved', 0o775, true);
        file_put_contents($learning . '/proposals/approved/proposal.2026-07-02.001.json', json_encode([
            'id' => 'proposal.2026-07-02.001',
            'status' => 'approved',
            'action' => 'ADD',
            'target_type' => 'skill',
            'target' => 'skills/test',
            'new' => 'Test skill rule',
            'boundary' => 'Applies to test',
            'created_at' => '2026-07-02T10:00:00+00:00',
            'proposed_by' => 'test',
            'approved_by' => 'reviewer',
            'approved_at' => '2026-07-02T11:00:00+00:00',
            'scope' => ['src/Test.php'],
            'validation' => ['make test'],
            'source_findings' => ['finding.2026-06-01.001'],
            'reason' => 'Active skill guidance applied.',
        ], JSON_THROW_ON_ERROR));

        // Active note
        file_put_contents($learning . '/notes/active/note.1.json', json_encode(['id' => 'note.1'], JSON_THROW_ON_ERROR));

        // Active constraint linked to proposal.2026-06-05.001
        file_put_contents($learning . '/constraints/active/constraint.rule1.json', json_encode([
            'id' => 'constraint.rule1',
            'source_proposal' => 'proposal.2026-06-05.001',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR));

        // Retired proposal history
        file_put_contents($learning . '/history/retired-proposals.jsonl', json_encode([
            'id' => 'retirement.1',
            'proposal_id' => 'proposal.2026-06-05.001',
            'retired_by' => 'tester',
            'retired_at' => '2026-06-10T10:00:00+00:00',
            'reason' => 'Fully captured in target canonical guidance; confirmed landed.',
        ], JSON_THROW_ON_ERROR) . "\n");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testGatewayReturnsCorpusAnalyticsWhenConfigured(): void
    {
        $gateway = new LearningCatalogGateway($this->root);
        $analytics = $gateway->corpusAnalytics();

        self::assertNotNull($analytics);
        self::assertSame(1, $analytics->summary['total_findings']);
        self::assertSame(2, $analytics->summary['total_proposals']);
        self::assertSame(1, $analytics->summary['total_active_notes']);
        self::assertSame(1, $analytics->summary['total_active_constraints']);
    }

    public function testGatewayReturnsNullWhenLearningIsAbsent(): void
    {
        $absent = sys_get_temp_dir() . '/agent_ui_absent_' . bin2hex(random_bytes(6));
        $gateway = new LearningCatalogGateway($absent);

        self::assertNull($gateway->corpusAnalytics());
    }

    public function testOverviewRendersCorpusEvolutionTeaser(): void
    {
        $action = new KnowledgeAction(
            new LearningCatalogGateway($this->root),
            new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
        );

        $body = $action->overview(new Request('GET', '/knowledge'))->body;

        self::assertStringContainsString('Corpus Evolution', $body);
        self::assertStringContainsString('Workflow Evolution &amp; Analytics', $body);
        self::assertStringContainsString('href="/knowledge?tab=analytics"', $body);
    }

    public function testAnalyticsTabRendersEvolutionLifecycleAndConsolidationSections(): void
    {
        $action = new KnowledgeAction(
            new LearningCatalogGateway($this->root),
            new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
        );

        $body = $action->overview(new Request('GET', '/knowledge', ['tab' => 'analytics']))->body;

        self::assertStringContainsString('Corpus Analytics &amp; Workflow Evolution', $body);
        self::assertStringContainsString('Issue #115 · Workflow Evolution across Monthly Cohorts', $body);
        self::assertStringContainsString('Issue #116 · Deconstructing the 80.7% Terminal Proposal Rate', $body);
        self::assertStringContainsString('Issue #117 · Consolidation &amp; Dream Diagnostics', $body);
        self::assertStringContainsString('Permanent Graduation', $body);
        self::assertStringContainsString('Compiled Down to Constraint', $body);
        self::assertStringContainsString('Consolidation Distributions', $body);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
