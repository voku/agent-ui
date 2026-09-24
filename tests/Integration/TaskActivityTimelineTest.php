<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Feature\History\TaskActivityComposer;
use voku\AgentUi\Feature\History\TaskActivityEvent;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * A timeline is a claim about when things happened, so every position needs an owner behind it.
 *
 * The page previously showed four kinds of agent-loop event and only the Contract
 * revision in force. What it did not show was spread across pages that each
 * answered their own question, so the order of a task's story existed only in the
 * reader's head. Widening it is easy; widening it without inventing chronology is
 * the part worth pinning, because a plausible-looking position is indistinguishable
 * from a correct one once it is rendered.
 */
final class TaskActivityTimelineTest extends TestCase
{
    private string $root;
    private string $templates;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-activity-' . bin2hex(random_bytes(6));
        $this->templates = dirname(__DIR__, 2) . '/templates';

        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /**
     * The one rule the whole surface rests on: no entry without an owner timestamp.
     *
     * Asserting that events exist would pass for a timeline that had guessed half
     * of them, so what is checked is that every rendered position is a string an
     * owner published, and that nothing arrives with an empty one.
     */
    public function testEveryPlacedEventCarriesAnOwnerPublishedTimestamp(): void
    {
        $composer = $this->composerWithCardAndContract();

        $activity = $composer->forTask('APP-1');

        self::assertNotSame([], $activity->events);
        foreach ($activity->events as $event) {
            self::assertNotSame('', $event->at, $event->kind . ' was placed without a timestamp');
            self::assertNotSame('', $event->owner, $event->kind . ' was placed without naming its owner');
        }
    }

    /** The card's creation is agent-kanban's fact, and it reaches the story. */
    public function testTheBoardCardCreationIsPartOfTheStory(): void
    {
        $activity = $this->composerWithCardAndContract()->forTask('APP-1');

        $created = $this->eventsOfKind($activity->events, 'task_created');

        self::assertCount(1, $created);
        self::assertSame('agent-kanban', $created[0]->owner);
    }

    /**
     * Superseded Contract revisions are owner-retained history, not noise.
     *
     * Showing only the revision in force told the reader what is agreed now and
     * hid that it was agreed twice, which is exactly the question someone
     * returning to a task asks.
     */
    public function testEveryContractRevisionAppearsNotOnlyTheCurrentOne(): void
    {
        $app = $this->applicationWithCard();
        $csrf = (new CsrfTokenManager())->token();
        $this->proposeContract($app, $csrf, 'First goal');
        $this->proposeContract($app, $csrf, 'Second goal', 'revise');

        $activity = $this->composer()->forTask('APP-1');

        self::assertGreaterThanOrEqual(
            2,
            count($this->eventsOfKind($activity->events, 'contract_proposed')),
            'A superseded Contract revision is owner-retained history and belongs in the story',
        );
    }

    /**
     * Newest first, by the owners' own timestamps rather than by the order they were read.
     *
     * The card's created date is rewritten to a known older value first. Without
     * that the fixture writes every artifact inside the same second, every
     * timestamp is equal, and any order at all satisfies a sort assertion - which
     * is how this test passed against a composer with its `usort` deleted.
     */
    public function testTheStoryIsOrderedNewestFirst(): void
    {
        $app = $this->applicationWithCard();
        $this->backdateCard('2020-01-01T00:00:00+00:00');
        $this->proposeContract($app, (new CsrfTokenManager())->token(), 'Fixture goal');

        $timestamps = array_map(static fn($event): string => $event->at, $this->composer()->forTask('APP-1')->events);

        self::assertGreaterThan(
            1,
            count(array_unique($timestamps)),
            'The fixture must contain distinct timestamps or it cannot test ordering at all',
        );

        $sorted = $timestamps;
        rsort($sorted, SORT_STRING);
        self::assertSame($sorted, $timestamps);
    }

    /**
     * A card agent-kanban cannot date produces no dated event.
     *
     * The owner models an unparsable created date as null, and the honest
     * rendering of null is absence from the timeline - not an entry with an empty
     * timestamp, which would sort to one end and read as a real position.
     */
    public function testACardWithNoCreatedDateContributesNoEvent(): void
    {
        $this->applicationWithCard();
        $this->stripCardCreatedDate();

        $activity = $this->composer()->forTask('APP-1');

        self::assertSame([], $this->eventsOfKind($activity->events, 'task_created'));
    }

    /**
     * No fact twice, however many owner projections happen to carry it.
     *
     * Two projections answer "when was this Contract approved" - the audit report
     * for the revision in force, the Contract store for all of them - and reading
     * both put the current approval on the page twice. Rendering found it; no
     * assertion about which events exist would have.
     */
    public function testNoFactIsPlacedTwice(): void
    {
        $app = $this->applicationWithCard();
        $csrf = (new CsrfTokenManager())->token();
        $this->proposeContract($app, $csrf, 'First goal');
        $this->approveContract($app, $csrf);

        $seen = [];
        foreach ($this->composer()->forTask('APP-1')->events as $event) {
            $key = $event->at . '|' . $event->kind;
            self::assertNotContains($key, $seen, $event->kind . ' was placed twice at ' . $event->at);
            $seen[] = $key;
        }
    }

    /**
     * What the work taught is part of when it happened, and agent-learning dates it.
     *
     * Findings used to live only on the Learning page, so the reader had to hold
     * "this Finding came out of that validation run" in their head. The Finding's
     * own createdAt puts it in the same story as the run.
     */
    public function testAFindingAppearsWithTheTimestampAgentLearningPublished(): void
    {
        $this->applicationWithCard();
        $this->writeFinding('finding.2026-09-24.aaa001', '2026-02-02T09:00:00+00:00');

        $created = $this->eventsOfKind($this->composer()->forTask('APP-1')->events, 'finding_created');

        self::assertCount(1, $created);
        self::assertSame('2026-02-02T09:00:00+00:00', $created[0]->at);
        self::assertSame('agent-learning', $created[0]->owner);
    }

    /**
     * The type refuses to hold a fact it cannot place, so no caller can fake one.
     *
     * A test that only checks the composer's current callers would not stop the
     * next one reaching for `?? ''` when an owner's timestamp turns out nullable.
     */
    public function testAnEventCannotExistWithoutAnOwnerTimestamp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaskActivityEvent('', 'agent-learning', 'guidance', 'Durable guidance', '');
    }

    /** And it refuses to hold one whose owner is not named. */
    public function testAnEventCannotExistWithoutNamingItsOwner(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaskActivityEvent('2026-01-01T00:00:00+00:00', '', 'guidance', 'Durable guidance', '');
    }

    private function approveContract(Application $app, string $csrf): void
    {
        $response = $app->handle(new Request('POST', '/task/APP-1/approve', body: [
            '_csrf' => $csrf,
            'actor' => 'claude',
        ]));
        self::assertSame(303, $response->status);
    }

    private function writeFinding(string $id, string $createdAt): void
    {
        $directory = $this->root . '/.agent-loop/learning/findings/validated';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true)) {
            throw new RuntimeException('Unable to create the learning fixture root.');
        }
        file_put_contents(
            $this->root . '/.agent-loop/learning/config.json',
            json_encode(
                ['schema_version' => '1.0', 'task_id_pattern' => '^[A-Z]+-[0-9]+$'],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ),
        );
        file_put_contents($directory . '/' . $id . '.json', json_encode([
            'id' => $id,
            'task_id' => 'APP-1',
            'session' => 'session_APP-1',
            'created_at' => $createdAt,
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private function backdateCard(string $createdAt): void
    {
        $path = $this->root . '/.agent-loop/todo/cards/APP-1.md';
        $card = (string) file_get_contents($path);
        $rewritten = preg_replace('/^- \*\*Created:\*\* .*$/m', '- **Created:** ' . $createdAt, $card, 1);
        self::assertIsString($rewritten);
        self::assertNotSame($card, $rewritten, 'The fixture card must carry a Created line to rewrite');
        file_put_contents($path, $rewritten);
    }

    private function stripCardCreatedDate(): void
    {
        $path = $this->root . '/.agent-loop/todo/cards/APP-1.md';
        $card = (string) file_get_contents($path);
        $rewritten = preg_replace('/^- \*\*Created:\*\* .*\n/m', '', $card, 1);
        self::assertIsString($rewritten);
        self::assertNotSame($card, $rewritten, 'The fixture card must carry a Created line to remove');
        file_put_contents($path, $rewritten);
    }

    /** A task no owner has timestamped renders as that, not as an empty frame. */
    public function testATaskWithNoTimestampedFactSaysSo(): void
    {
        $app = $this->applicationWithCard();

        $body = $app->handle(new Request('GET', '/task/APP-1/history'))->body;

        self::assertStringContainsString('History', $body);
        self::assertStringNotContainsString('Known, but not placed in time', $body);
    }

    /** The history page renders the composed story through the real router. */
    public function testTheHistoryPageRendersTheComposedStory(): void
    {
        $app = $this->applicationWithCard();
        $this->proposeContract($app, (new CsrfTokenManager())->token(), 'Fixture goal');

        $response = $app->handle(new Request('GET', '/task/APP-1/history'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Contract proposed', $response->body);
        self::assertStringContainsString('agent-kanban', $response->body);
    }

    /**
     * @param list<\voku\AgentUi\Feature\History\TaskActivityEvent> $events
     * @return list<\voku\AgentUi\Feature\History\TaskActivityEvent>
     */
    private function eventsOfKind(array $events, string $kind): array
    {
        return array_values(array_filter($events, static fn($event): bool => $event->kind === $kind));
    }

    private function composerWithCardAndContract(): TaskActivityComposer
    {
        $app = $this->applicationWithCard();
        $this->proposeContract($app, (new CsrfTokenManager())->token(), 'Fixture goal');

        return $this->composer();
    }

    private function composer(): TaskActivityComposer
    {
        return new TaskActivityComposer(
            new AuditTrailGateway($this->root),
            new HumanDecisionGateway($this->root),
            new LearningCatalogGateway($this->root),
            new BoardProjectionGateway($this->root),
        );
    }

    private function proposeContract(
        Application $app,
        string $csrf,
        string $goal,
        string $action = 'propose',
    ): void {
        $response = $app->handle(new Request('POST', '/task/APP-1/contract', body: [
            '_csrf' => $csrf,
            'contract_action' => $action,
            'goal' => $goal,
            'scope' => 'src',
            'validation' => 'composer test',
            'planned_by' => 'claude',
        ]));
        self::assertSame(303, $response->status);
    }

    private function applicationWithCard(): Application
    {
        $app = new Application($this->root, $this->templates);
        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => 'APP-1',
            'title' => 'Build login system',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Fixture',
            'task_brief' => 'Fixture brief.',
            'validation' => 'composer test',
        ]));
        self::assertSame(303, $created->status);

        return $app;
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
