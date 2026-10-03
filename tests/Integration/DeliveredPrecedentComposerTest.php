<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;
use voku\AgentLoop\ProjectLayout;
use voku\AgentRecallCompiler\CanonicalJson;
use voku\AgentUi\Feature\Knowledge\DeliveredPrecedentComposer;
use voku\AgentUi\Feature\Knowledge\DeliveredPrecedents;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;

/**
 * The join between Recall's delivery facts and Learning's origin chain, case by case.
 *
 * The end-to-end page test covers the delivered precedent. What it cannot easily produce is
 * the rest of what Recall can say about one: held back by active guidance, recorded without a
 * note id (a report written before the field existed), or naming a note Learning does not
 * return. Each of those has to stay what it is - a withheld note is not "delivered", and an
 * origin that cannot be followed is not a task.
 */
final class DeliveredPrecedentComposerTest extends TestCase
{
    private const NOTE_TITLE = 'Inline var casts need PHPDoc delimiters';

    private string $root;
    private string $noteId;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-delivered-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/recall/TEST-1', 0o775, true)) {
            throw new RuntimeException('Unable to create the fixture root.');
        }
        $learningRoot = (new ProjectLayout($this->root))->learningRoot();
        $finding = (new FindingCreator())->createValidated(
            root: $learningRoot,
            taskId: 'APP-1',
            session: 'session-1',
            createdBy: 'tester',
            scope: ['src/Example.php'],
            observation: 'PHPStan ignored an inline var cast written with a single-star comment.',
            evidence: [['type' => 'manual_verification', 'summary' => 'Reproduced with composer phpstan.']],
            hypothesis: 'The single-star comment is not a PHPDoc comment.',
            validatedConclusion: 'Inline var casts need a double-star PHPDoc comment to be honoured.',
            confidence: 'high',
            sensitivity: 'public',
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: 'phpstan.inline_var_requires_phpdoc',
            validationCase: new ValidationCase('an inline var comment', 'an agent writes it', 'use a double-star PHPDoc comment'),
        );
        $this->noteId = (new LearningNoteService())->publish(
            $learningRoot,
            new LearningNoteDraft(
                sourceFindings: [$finding->finding->id],
                sourceProposals: [],
                tags: ['phpstan'],
                repositoryEvidence: [],
                content: new LearningNoteContent(
                    title: self::NOTE_TITLE,
                    context: 'PHPStan narrows inline casts only with a PHPDoc comment.',
                    guidance: 'Write a double-star PHPDoc comment.',
                    whyItWorks: 'Single-star comments are plain comments.',
                    whenToApply: 'Whenever an inline var cast is written.',
                    whenNotToApply: 'When the type is inferred.',
                    verification: 'composer phpstan',
                ),
            ),
            $this->root,
        )->id;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAPrecedentHeldBackByActiveGuidanceIsNotDeliveredAndKeepsRecallsReason(): void
    {
        $this->writeRecall([$this->item('Held back', false, $this->noteId, 'covered_by_active_guidance:proposal.2026-10-03.001')]);

        $result = $this->composer()->forTask('TEST-1');

        self::assertSame(DeliveredPrecedents::AVAILABLE, $result->status);
        self::assertCount(1, $result->items);
        self::assertFalse($result->items[0]->delivered);
        self::assertSame('covered_by_active_guidance:proposal.2026-10-03.001', $result->items[0]->whyNot, 'Recall\'s reason, verbatim.');
        self::assertSame(['APP-1'], $result->items[0]->taughtBy, 'Not delivered does not change who taught it.');
    }

    public function testAnItemRecordedWithoutANoteIdHasNoOriginAndSaysWhy(): void
    {
        $this->writeRecall([$this->item('Written before the field existed', true, null, null)]);

        $item = $this->composer()->forTask('TEST-1')->items[0];

        self::assertTrue($item->delivered);
        self::assertNull($item->noteId);
        self::assertNull($item->taughtBy, 'No id is not "taught by no task".');
        self::assertStringContainsString('did not record which note', $item->originNote);
    }

    public function testANoteLearningDoesNotReturnHasNoOriginAndSaysWhy(): void
    {
        $this->writeRecall([$this->item('Unknown note', true, 'learning-note.2026-01-01.ffffff', null)]);

        $item = $this->composer()->forTask('TEST-1')->items[0];

        self::assertSame('learning-note.2026-01-01.ffffff', $item->noteId);
        self::assertNull($item->taughtBy);
        self::assertStringContainsString('cannot be followed', $item->originNote);
    }

    public function testOnlyLearningPrecedentsAreListed(): void
    {
        $this->writeRecall([
            [
                'id' => 'map-omitted:1',
                'kind' => 'map_omission',
                'what' => 'symbol:Foo::bar',
                'why' => 'Considered.',
                'how' => 'agent-map omission evidence.',
                'authority' => 'derived_navigation',
                'use' => 'investigate_if_relevant',
                'state' => 'unknown',
                'selected' => false,
                'source_ref' => 'map.json',
                'evidence_ids' => [],
                'why_not' => 'bounded context budget',
            ],
            $this->item('The precedent', true, $this->noteId, null),
        ]);

        $items = $this->composer()->forTask('TEST-1')->items;

        self::assertCount(1, $items);
        self::assertSame('The precedent', $items[0]->title);
    }

    public function testNoCompiledContextIsReportedAsMissingNotAsNoPrecedents(): void
    {
        $result = $this->composer()->forTask('NEVER-1');

        self::assertSame(DeliveredPrecedents::MISSING, $result->status);
        self::assertSame([], $result->items);
        self::assertStringContainsString('No persisted compiled context', (string) $result->problem);
    }

    private function composer(): DeliveredPrecedentComposer
    {
        return new DeliveredPrecedentComposer(new ContextExplanationGateway($this->root), new LearningCatalogGateway($this->root));
    }

    /** @return array<string, mixed> */
    private function item(string $title, bool $selected, ?string $subjectId, ?string $whyNot): array
    {
        return [
            'id' => 'learning-precedent:' . ($subjectId ?? 'legacy'),
            'kind' => 'learning_precedent',
            'what' => $title,
            'why' => 'Deterministic LearningNote relevance: scope_match.',
            'how' => 'LearningNoteRecallProvider exact path-scope/tag selection.',
            'authority' => 'learning_precedent',
            'use' => $selected ? 'historical_precedent_not_instruction' : 'machine_fact_only',
            'state' => 'verified',
            'selected' => $selected,
            'source_ref' => 'agent-learning:' . ($subjectId ?? 'legacy'),
            'evidence_ids' => [],
        ] + ($whyNot === null ? [] : ['why_not' => $whyNot]) + ($subjectId === null ? [] : ['subject_id' => $subjectId]);
    }

    /** @param list<array<string, mixed>> $items */
    private function writeRecall(array $items): void
    {
        $directory = $this->root . '/.agent-loop/recall/TEST-1';
        $bundle = ['schema_version' => '1.0', 'task' => ['id' => 'TEST-1', 'revision' => 1], 'outcome_stats' => []];
        $bundleSha256 = CanonicalJson::digest($bundle);
        $selection = [
            'schema_version' => '1.0',
            'bundle_sha256' => $bundleSha256,
            'selected_constraints' => [],
            'evaluated_guidance' => [],
            'warnings' => [],
            'context_explain' => $items,
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
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
