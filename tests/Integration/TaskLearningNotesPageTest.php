<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningLineageService;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;
use voku\AgentLoop\ProjectLayout;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * The Finding -> LearningNote rung of the learning ladder is visible, and reading it changes nothing.
 *
 * `/task/{id}/learning` listed Findings, Proposals and Guidance and had no place for
 * the LearningNote between the first and the rest, so the step the learning ladder is
 * built around could not be seen. The note comes from Learning's lineage graph, which
 * is a derived cache: this page is a GET, so it asks Learning not to repair that cache
 * and says so when it is behind, instead of showing an empty list that reads as
 * "this task taught nothing".
 */
final class TaskLearningNotesPageTest extends TestCase
{
    private const string TITLE = 'Inline var casts need PHPDoc delimiters';

    private string $root;
    private string $templates;
    private string $learningRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-notes-' . bin2hex(random_bytes(6));
        $this->templates = dirname(__DIR__, 2) . '/templates';
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
        $this->learningRoot = (new ProjectLayout($this->root))->learningRoot();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTheNoteATaskTaughtIsListedWithItsSourceFinding(): void
    {
        $findingId = $this->finding('APP-1');
        $this->publishNote($findingId);

        $main = $this->main($this->app(), 'APP-1');

        self::assertStringContainsString('Learning notes', $main);
        self::assertStringContainsString(self::TITLE, $main);
        self::assertStringContainsString('Write /** @var Type $var */', htmlspecialchars_decode($main));
        self::assertStringContainsString('href="/knowledge/findings/' . $findingId . '"', $main);
        self::assertStringContainsString('precedent, not policy', $main);
    }

    /**
     * Another active note is not something this task taught.
     *
     * `precedentsForTask()` tops its list up with every other active note so Recall can
     * match them by scope. Rendering that list as "the notes of this task" would claim
     * a lineage Learning never recorded.
     */
    public function testAnActiveNoteThatIsNotInThisTasksLineageIsNotListed(): void
    {
        $first = $this->finding('APP-1');
        // Written before the note is published: publishing rebuilds the projection, and
        // a Finding written afterwards would leave it behind, which is a different case.
        $this->finding('APP-2');
        $this->publishNote($first);

        $main = $this->main($this->app(), 'APP-2');

        self::assertStringNotContainsString(self::TITLE, $main);
        self::assertStringContainsString("No LearningNote is derived from this task's lineage.", $main);
    }

    public function testAStaleProjectionIsReportedAndLeftStale(): void
    {
        $this->publishNote($this->finding('APP-1'));
        // Writing a Finding moves the durable revision without rebuilding the graph.
        $this->finding('APP-3');
        $database = $this->databasePath();
        $before = [hash_file('sha256', $database), filemtime($database)];

        $main = $this->main($this->app(), 'APP-1');

        self::assertStringContainsString('<p class="empty" role="status">', $main, 'The caveat must be announced, not just printed.');
        self::assertStringContainsString('absent or behind the durable records', $main);
        self::assertStringNotContainsString(self::TITLE, $main, 'A stale projection must not be answered from a rebuild.');
        self::assertStringNotContainsString('No LearningNote is derived', $main, 'Behind is not the same as empty.');
        clearstatcache();
        self::assertSame($before, [hash_file('sha256', $database), filemtime($database)], 'Viewing must not touch the cache.');
        $this->expectException(RuntimeException::class);
        (new LearningLineageService())->verifyCurrent($this->learningRoot);
    }

    public function testAnAbsentProjectionIsReportedAndNotCreated(): void
    {
        $this->publishNote($this->finding('APP-1'));
        $this->removeDirectory($this->learningRoot . '/.derived');

        $main = $this->main($this->app(), 'APP-1');

        self::assertStringContainsString('absent or behind the durable records', $main);
        self::assertDirectoryDoesNotExist($this->learningRoot . '/.derived', 'A page view must not build the projection.');
    }

    public function testTheRestOfThePageStillRendersWhenTheProjectionIsBehind(): void
    {
        $findingId = $this->finding('APP-1');
        $this->publishNote($findingId);
        $this->finding('APP-3');

        $main = $this->main($this->app(), 'APP-1');

        self::assertStringContainsString($findingId, $main, 'Findings come from the catalog, not the projection.');
    }

    public function testAProjectWithoutLearningSaysSoInsteadOfClaimingNoNotes(): void
    {
        $main = $this->main($this->app(), 'APP-1');

        self::assertStringContainsString('no Learning root', $main);
        self::assertStringNotContainsString('No LearningNote is derived', $main);
    }

    private function finding(string $taskId): string
    {
        $created = (new FindingCreator())->createValidated(
            root: $this->learningRoot,
            taskId: $taskId,
            session: 'session-' . $taskId,
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
            validationCase: new ValidationCase(
                given: 'an inline var comment',
                when: 'an agent writes it',
                then: 'use a double-star PHPDoc comment',
            ),
        );

        return $created->finding->id;
    }

    private function publishNote(string $findingId): void
    {
        $service = new LearningNoteService();
        $service->publish(
            $this->learningRoot,
            new LearningNoteDraft(
                sourceFindings: [$findingId],
                sourceProposals: [],
                tags: ['phpstan'],
                repositoryEvidence: [],
                content: new LearningNoteContent(
                    title: self::TITLE,
                    context: 'PHPStan narrows inline casts only when written as a PHPDoc comment.',
                    guidance: 'Write /** @var Type $var */, never /* @var Type $var */.',
                    whyItWorks: 'Single-star block comments are plain comments to the parser.',
                    whenToApply: 'Whenever an inline var cast is written or reviewed.',
                    whenNotToApply: 'When the type is already inferred.',
                    verification: 'composer phpstan',
                ),
            ),
            $this->root,
        );
    }

    private function app(): Application
    {
        $app = new Application($this->root, $this->templates);
        $csrf = (new CsrfTokenManager())->token();
        foreach (['APP-1', 'APP-2', 'APP-3'] as $card) {
            $created = $app->handle(new Request('POST', '/board/new', body: [
                '_csrf' => $csrf,
                'card_id' => $card,
                'title' => 'Card ' . $card,
                'lane' => 'BACKLOG',
                'status' => 'todo',
                'summary' => 'Summary',
                'task_brief' => 'Brief',
                'validation' => 'composer test',
                'priority' => '2',
                'assignee' => 'developer',
            ]));
            self::assertSame(303, $created->status);
        }

        return $app;
    }

    /** The page body between `<main>` tags, so layout CSS and script text cannot satisfy an assertion. */
    private function main(Application $app, string $taskId): string
    {
        $response = $app->handle(new Request('GET', '/task/' . $taskId . '/learning'));
        self::assertSame(200, $response->status);
        $start = strpos($response->body, '<main');
        $end = strpos($response->body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($response->body, $start, $end - $start);
    }

    private function databasePath(): string
    {
        return realpath($this->learningRoot) . '/.derived/lineage/graph.sqlite';
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
