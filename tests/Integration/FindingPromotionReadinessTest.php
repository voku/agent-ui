<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Knowledge\KnowledgeAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\View\TemplateRenderer;

final class FindingPromotionReadinessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/agent_ui_promotion_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/learning/findings/validated', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/learning/config.json',
            json_encode(['schema_version' => '1.0', 'task_id_pattern' => '^[A-Z]+-[0-9]+$'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testAFindingNobodyTriagedIsReportedAsNeverSurfacing(): void
    {
        $this->writeFinding('finding.2026-09-09.aaa001', classified: false);

        $promotion = (new LearningCatalogGateway($this->root))->promotionReadiness('finding.2026-09-09.aaa001');

        self::assertNotNull($promotion);
        self::assertFalse($promotion->promotable);
        // The blockers are the owner's identifiers, passed through unchanged.
        self::assertSame([
            'classification_not_add_learning_note',
            'pattern_key_missing',
            'validation_case_missing',
        ], $promotion->blockers);
    }

    public function testATriagedFindingIsPromotableAndKeepsItsPatternKey(): void
    {
        $this->writeFinding('finding.2026-09-09.aaa002', patternKey: 'pattern.reuse.ok');

        $promotion = (new LearningCatalogGateway($this->root))->promotionReadiness('finding.2026-09-09.aaa002');

        self::assertNotNull($promotion);
        self::assertTrue($promotion->promotable);
        self::assertSame([], $promotion->blockers);
        self::assertSame('pattern.reuse.ok', $promotion->patternKey);
    }

    public function testTheFindingPageSaysPlainlyThatNothingWillSurfaceIt(): void
    {
        $this->writeFinding('finding.2026-09-09.aaa003', classified: false);

        $body = $this->action()->finding('finding.2026-09-09.aaa003')->body;

        self::assertStringContainsString('Will this be seen again?', $body);
        self::assertStringContainsString('Nothing will ever surface this Finding', $body);
        self::assertStringContainsString('nobody classified it as one to keep', $body);
        // The owner identifier stays visible next to the label, so a newer
        // owner vocabulary is never silently reworded away.
        self::assertStringContainsString('classification_not_add_learning_note', $body);
        self::assertStringContainsString('recorded and never read back', $body);
    }

    public function testThePageDoesNotOfferToPromote(): void
    {
        $this->writeFinding('finding.2026-09-09.aaa004', patternKey: 'pattern.reuse.ok');

        $body = $this->action()->finding('finding.2026-09-09.aaa004')->body;

        self::assertStringContainsString('This Finding can become a LearningNote', $body);
        self::assertStringContainsString('Promotion is still a human decision', $body);
        self::assertStringNotContainsString('<form', $body);
    }

    public function testTheOverviewCountsTheOwnersVerdictsAndNamesAWriteOnlyStore(): void
    {
        $this->writeFinding('finding.2026-09-09.bbb001', classified: false);
        $this->writeFinding('finding.2026-09-09.bbb002', classified: false);

        $body = $this->action()->overview(new Request('GET', '/knowledge'))->body;

        self::assertStringContainsString('Reusable knowledge', $body);
        self::assertStringContainsString('0 / 2', $body);
        self::assertStringContainsString('write-only', $body);
    }

    public function testOneTriagedFindingRemovesTheWriteOnlyClaim(): void
    {
        $this->writeFinding('finding.2026-09-09.ccc001', classified: false);
        $this->writeFinding('finding.2026-09-09.ccc002', patternKey: 'pattern.reuse.ok');

        $body = $this->action()->overview(new Request('GET', '/knowledge'))->body;

        self::assertStringContainsString('1 / 2', $body);
        self::assertStringNotContainsString('write-only', $body);
    }

    public function testAProjectWithNoLearningIsUnavailableRatherThanTidy(): void
    {
        // "No Learning here" and "Learning here holds nothing reusable" are
        // different facts, and only one of them is reassuring.
        $absent = sys_get_temp_dir() . '/agent_ui_promotion_absent_' . bin2hex(random_bytes(6));
        $gateway = new LearningCatalogGateway($absent);

        self::assertNull($gateway->promotionReadiness('finding.2026-09-09.zzz999'));
        self::assertNull($gateway->promotionCandidates());
    }

    public function testAnEmptyLearningRootIsNotReportedAsUnavailable(): void
    {
        $gateway = new LearningCatalogGateway($this->root);

        self::assertSame([], $gateway->promotionCandidates());
    }

    public function testTheOverviewSaysNothingWasAskedWhenLearningIsUnavailable(): void
    {
        $absent = sys_get_temp_dir() . '/agent_ui_promotion_absent_' . bin2hex(random_bytes(6));
        mkdir($absent, 0o775, true);

        try {
            $body = (new KnowledgeAction(
                new LearningCatalogGateway($absent),
                new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
            ))->overview(new Request('GET', '/knowledge'))->body;
        } finally {
            $this->removeDir($absent);
        }

        self::assertStringContainsString('nothing was asked', $body);
        self::assertStringNotContainsString('no Finding eligible to become a LearningNote', $body);
    }

    private function action(): KnowledgeAction
    {
        return new KnowledgeAction(
            new LearningCatalogGateway($this->root),
            new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
        );
    }

    private function writeFinding(string $id, bool $classified = true, string $patternKey = 'pattern.unused'): void
    {
        $record = [
            'id' => $id,
            'task_id' => 'UI-25',
            'session' => 'session_UI-25',
            'created_at' => '2026-09-09T10:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Example.php'],
            'observation' => 'A reusable lesson was recorded.',
            'evidence' => [['type' => 'manual_verification', 'summary' => 'Reproduced in the fixture.']],
            'hypothesis' => 'It generalises.',
            'validated_conclusion' => 'It generalises, and here is when.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'internal',
        ];
        if ($classified) {
            $record['classification'] = 'ADD_LEARNING_NOTE';
            $record['pattern_key'] = $patternKey;
            $record['validation_case'] = [
                'given' => 'A later related task.',
                'when' => 'The same shape appears.',
                'then' => 'The precedent is available without becoming authority.',
            ];
        }
        file_put_contents(
            $this->root . '/.agent-loop/learning/findings/validated/' . $id . '.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
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
