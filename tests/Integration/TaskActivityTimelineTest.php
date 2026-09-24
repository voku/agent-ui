<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Feature\History\TaskActivity;
use voku\AgentUi\Feature\History\TaskActivityComposer;
use voku\AgentUi\Feature\History\TaskActivityEvent;
use voku\AgentUi\Feature\History\UntimedOwnerFact;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\AuditTimelineEntry;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskAuditSnapshot;
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
        $activity = $this->activityWithCardAndContract();

        self::assertNotSame([], $activity->events);
        foreach ($activity->events as $event) {
            self::assertNotSame('', $event->at, $event->kind . ' was placed without a timestamp');
            self::assertNotSame('', $event->owner, $event->kind . ' was placed without naming its owner');
        }
    }

    /** The card's creation is agent-kanban's fact, and it reaches the story. */
    public function testTheBoardCardCreationIsPartOfTheStory(): void
    {
        $activity = $this->activityWithCardAndContract();

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

        $activity = $this->activityFor('APP-1');

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

        $timestamps = array_map(static fn($event): string => $event->at, $this->activityFor('APP-1')->events);

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
    public function testACardWithNoCreatedDateIsReportedAsUntimedNotDropped(): void
    {
        $this->applicationWithCard();
        $this->stripCardCreatedDate();

        $activity = $this->activityFor('APP-1');

        self::assertSame([], $this->eventsOfKind($activity->events, 'task_created'));
        $untimed = array_values(array_filter($activity->untimed, static fn(UntimedOwnerFact $f) => $f->kind === 'task_created'));
        self::assertCount(1, $untimed, 'a card with no Created line still exists and the page has to say so');
        self::assertSame('agent-kanban', $untimed[0]->owner);
        self::assertStringContainsString('no time', $untimed[0]->missing);
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
        foreach ($this->activityFor('APP-1')->events as $event) {
            // The whole fact, not just when and what kind: two Contract
            // revisions planned inside the same second are two facts that share
            // a timestamp and a kind, and keying on those alone reported the
            // second one as a duplicate of the first.
            $key = implode('|', [$event->at, $event->owner, $event->kind, $event->title, $event->detail]);
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

        $created = $this->eventsOfKind($this->activityFor('APP-1')->events, 'finding_created');

        self::assertCount(1, $created);
        self::assertSame('2026-02-02T09:00:00+00:00', $created[0]->at);
        self::assertSame('agent-learning', $created[0]->owner);
    }

    /**
     * A board that cannot be read is not a board with no card.
     *
     * The first version caught every Throwable around the card read, so a
     * malformed card or an unreadable board root silently dropped the creation
     * event while the page still read as the complete story. Only the absence the
     * gateway models - InvalidArgumentException for an id no board holds - counts
     * as absence now.
     */
    public function testABoardFailureThatIsNotAMissingCardIsNotSwallowed(): void
    {
        $this->applicationWithCard();
        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/APP-1.md',
            "# APP-1: Build login system\n\n- **Ticket:** APP-1\n- **Lane:** BACKLOG\n"
                . "- **Status:** todo\n- **Priority:** not-a-number\n- **Format version:** 1\n",
        );

        $this->expectException(Throwable::class);

        $this->activityFor('APP-1');
    }

    /** A task no board holds still composes, from the owners that do hold it. */
    public function testATaskWithNoBoardCardStillComposes(): void
    {
        $app = new Application($this->root, $this->templates);
        self::assertSame(404, $app->handle(new Request('GET', '/task/APP-404'))->status);

        $activity = $this->activityFor('APP-404');

        self::assertSame([], $this->eventsOfKind($activity->events, 'task_created'));
    }

    /**
     * Ordering reads the instant, because ATOM carries an offset.
     *
     * `2026-01-01T09:00:00+02:00` is 07:00 UTC and therefore earlier than
     * `2026-01-01T08:00:00+00:00`, but sorts after it as text. Every owner in
     * this repository writes `+00:00` today, which is exactly why comparing
     * strings kept passing. The card is rewritten to an instant an hour before
     * the Contract, expressed in +09:00 so that it sorts *later* as text, and
     * the assertion below is only meaningful because that precondition holds.
     */
    public function testEventsWithDifferentOffsetsAreOrderedByInstant(): void
    {
        $app = $this->applicationWithCard();
        $this->proposeContract($app, (new CsrfTokenManager())->token(), 'Fixture goal');

        $contract = (new HumanDecisionGateway($this->root))->contract('APP-1');
        self::assertNotNull($contract);
        $cardCreated = (new DateTimeImmutable($contract->createdAt))
            ->modify('-1 hour')
            ->setTimezone(new DateTimeZone('+09:00'))
            ->format(DateTimeInterface::ATOM);

        self::assertGreaterThan(
            0,
            strcmp($cardCreated, $contract->createdAt),
            'The card must sort later as text than the Contract, or this test cannot tell the two orderings apart',
        );

        $this->backdateCard($cardCreated);
        $events = $this->activityFor('APP-1')->events;

        self::assertNotSame(
            'task_created',
            $events[0]->kind,
            'Newest first must mean the later instant, not the larger string',
        );
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

    /**
     * A proposal record in the shape agent-learning's own fixtures use.
     *
     * An applied memory/skill proposal must prove its target: a real file at
     * target_source_ref whose sha256 matches. The fixture writes that file
     * rather than dating the record before the proof policy began, which would
     * be testing a legacy shape to avoid the check.
     *
     * @param array<string, mixed> $fields
     */
    private function writeProposal(string $id, string $status, string $findingId, array $fields = []): void
    {
        $directory = $this->root . '/.agent-loop/learning/proposals/' . $status;
        if (!is_dir($directory) && !mkdir($directory, 0o775, true)) {
            throw new RuntimeException('Unable to create the proposal fixture directory.');
        }

        $record = array_merge([
            'id' => $id,
            'created_at' => '2026-03-01T09:00:00+00:00',
            'action' => 'REPLACE',
            'target_type' => 'skill',
            'target' => 'fixture-skill',
            // Must stay within the source finding's evidence; the owner rejects
            // a proposal broader than what its finding observed.
            'scope' => ['src/Example.php'],
            'source_findings' => [$findingId],
            'old' => 'Before.',
            'new' => 'After.',
            'reason' => 'The fixture needs a proposal.',
            'boundary' => 'Fixture only.',
            'validation' => ['Run the suite.'],
            'status' => $status,
            'proposed_by' => 'agent_alpha',
        ], $fields);

        if ($status === 'applied' || $status === 'retired') {
            $target = $this->root . '/skills/fixture-skill.md';
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o775, true)) {
                throw new RuntimeException('Unable to create the target fixture directory.');
            }
            file_put_contents($target, 'After.');
            $record['applied_validation'] = [
                'tests_passed' => true,
                'target_source_ref' => 'skills/fixture-skill.md',
                'target_content_hash' => hash_file('sha256', $target),
            ];
        }

        file_put_contents(
            $directory . '/' . $id . '.json',
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
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

    /**
     * The same snapshot with a different timeline, so a raw owner string can be tested.
     *
     * Every owner in this repository parses its own timestamps before projecting
     * them, so none of them can be made to hand the composer an unparsable one
     * from a fixture. The audit report's timeline entries are the exception -
     * they carry whatever string the report held - and forTask() taking the
     * snapshot as an argument is what makes substituting one possible at all.
     */
    private function snapshotWithTimeline(TaskAuditSnapshot $snapshot, AuditTimelineEntry ...$timeline): TaskAuditSnapshot
    {
        $reflection = new \ReflectionClass(TaskAuditSnapshot::class);
        $arguments = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = $name === 'timeline' ? array_values($timeline) : $snapshot->{$name};
        }

        return $reflection->newInstanceArgs($arguments);
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

    /**
     * A string that is not a moment is an absence, and absences are shown as absences.
     *
     * The comparator used to fall back to strcmp() when it could not parse a
     * timestamp, which ordered a word against a moment by spelling. That is not
     * just approximate: with one unparsable value among two real ones the
     * relation stopped being transitive, so the rendered order depended on which
     * owner the composer happened to read first. The value is still shown - it
     * is a fact about the owner - just not on the timeline.
     */
    public function testAnOwnerStringThatNamesNoMomentIsListedRatherThanSorted(): void
    {
        $this->applicationWithCard();
        $snapshot = $this->snapshotWithTimeline(
            (new AuditTrailGateway($this->root))->task('APP-1'),
            new AuditTimelineEntry('whenever', 'run_started', 'Run started', 'Reported without a time.'),
        );

        $activity = $this->composer()->forTask($snapshot);

        self::assertSame([], $this->eventsOfKind($activity->events, 'run_started'));
        $untimed = array_values(array_filter($activity->untimed, static fn(UntimedOwnerFact $f) => $f->kind === 'run_started'));
        self::assertCount(1, $untimed, 'the fact is still the owner\'s; only its position was never the UI\'s to choose');
        self::assertSame('agent-loop', $untimed[0]->owner);
        self::assertStringContainsString('whenever', $untimed[0]->missing);
    }

    /**
     * `now` and `tomorrow` parse into a DateTimeImmutable, against the clock.
     *
     * This is why the guard is not simply "does DateTimeImmutable accept it":
     * that accepts relative words and resolves them to today, which would date a
     * fact by when the page was rendered. Rollover is the other half - February
     * 30th does not throw, it becomes March 2nd and says so only in
     * getLastErrors(). And a relative suffix after a real moment is the third:
     * the string opens with a date the owner did write and ends somewhere else
     * entirely.
     *
     * @return list<array{string}>
     */
    public static function stringsThatAreNotMoments(): array
    {
        return [
            ['now'],
            ['tomorrow'],
            ['yesterday'],
            ['unknown'],
            ['2026-09-24'],
            ['2026-13-45T00:00:00+00:00'],
            // These two do not throw. PHP rolls them over - to 2026-03-02 and to
            // 2025-11-30 - and reports it only through getLastErrors(), so a
            // catch block alone would have placed both on the timeline, at a
            // date no owner ever wrote.
            ['2026-02-30T00:00:00+00:00'],
            ['2026-00-00T00:00:00+00:00'],
            // And these open with a real moment, so anchoring only the start of
            // the string let them through: DateTimeImmutable keeps reading and
            // applies the relative part, returning October 1st, the 25th, the
            // 28th and the 21st respectively - without a warning.
            ['2026-09-24T08:00:00+00:00 +1 week'],
            ['2026-09-24T08:00 tomorrow'],
            ['2026-09-24T08:00:00+00:00 next monday'],
            ['2026-09-24T08:00:00Z 3 days ago'],
        ];
    }

    #[DataProvider('stringsThatAreNotMoments')]
    public function testAnEventRefusesAStringThatNamesNoMoment(string $at): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TaskActivityEvent($at, 'agent-loop', 'run_started', 'Run started', '');
    }

    /**
     * The formats owners do publish all stay placeable.
     *
     * The guard has to be narrow enough to reject a word and wide enough that an
     * owner writing `Z` instead of `+00:00`, or adding microseconds, does not
     * silently empty the timeline into the untimed list.
     *
     * @return list<array{string}>
     */
    public static function momentsOwnersPublish(): array
    {
        return [
            ['2026-09-24T08:00:00+00:00'],
            ['2026-09-24T08:00:00Z'],
            ['2026-09-24T08:00:00.123456+02:00'],
            ['2026-09-24 08:00:00'],
        ];
    }

    #[DataProvider('momentsOwnersPublish')]
    public function testAnEventAcceptsTheFormatsOwnersPublish(string $at): void
    {
        self::assertSame($at, (new TaskActivityEvent($at, 'agent-loop', 'run_started', 'Run started', ''))->at);
    }

    /**
     * Equal instants order by the facts, not by the order the composer reads owners.
     *
     * Two owners can publish the same second, and PHP's stable sort then left the
     * rendered order equal to the order addBoard()/addContracts()/addLearning()
     * happen to be called in - so reordering those three lines would have
     * reordered a rendered page with nothing in the diff to say so.
     */
    public function testEqualInstantsOrderByTheFactsThemselves(): void
    {
        $this->applicationWithCard();
        $at = '2026-03-03T10:00:00+00:00';
        $snapshot = $this->snapshotWithTimeline(
            (new AuditTrailGateway($this->root))->task('APP-1'),
            new AuditTimelineEntry($at, 'zzz_last', 'Zeta', 'Same second.'),
            new AuditTimelineEntry($at, 'aaa_first', 'Alpha', 'Same second.'),
        );

        $kinds = array_map(
            static fn(TaskActivityEvent $e) => $e->kind,
            array_values(array_filter(
                $this->composer()->forTask($snapshot)->events,
                static fn(TaskActivityEvent $e) => $e->at === $at,
            )),
        );

        self::assertSame(['aaa_first', 'zzz_last'], $kinds);
    }

    /**
     * What happened to a proposal after approval is part of the story, dated by its owner.
     *
     * Until agent-learning 0.18.26 its projection stopped at approval, so a
     * proposal's acknowledgement, application and retirement were known to the
     * owner's record and invisible here.
     */
    public function testEveryLaterProposalTransitionIsPlacedAtTheOwnerMoment(): void
    {
        $this->applicationWithCard();
        $this->writeFinding('finding.2026-03-01.001', '2026-03-01T08:00:00+00:00');
        $this->writeProposal('proposal.2026-03-01.001', 'acknowledged', 'finding.2026-03-01.001', [
            'action' => 'NO_DURABLE_LEARNING',
            'target_type' => null,
            'target' => null,
            'acknowledged_by' => 'reviewer',
            'acknowledged_at' => '2026-03-02T10:00:00+00:00',
        ]);
        $this->writeProposal('proposal.2026-03-01.002', 'retired', 'finding.2026-03-01.001', [
            'approved_by' => 'maintainer',
            'approved_at' => '2026-03-01T11:00:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => '2026-03-03T10:00:00+00:00',
            'retired_by' => 'curator',
            'retired_at' => '2026-03-04T10:00:00+00:00',
        ]);

        $events = $this->activityFor('APP-1')->events;

        $expected = [
            'proposal_acknowledged' => ['2026-03-02T10:00:00+00:00', 'proposal.2026-03-01.001 by reviewer'],
            'proposal_applied' => ['2026-03-03T10:00:00+00:00', 'proposal.2026-03-01.002 by maintainer'],
            'proposal_retired' => ['2026-03-04T10:00:00+00:00', 'proposal.2026-03-01.002 by curator'],
        ];
        foreach ($expected as $kind => [$at, $detail]) {
            $placed = $this->eventsOfKind($events, $kind);
            self::assertCount(1, $placed, $kind . ' must be placed once');
            self::assertSame($at, $placed[0]->at);
            self::assertSame('agent-learning', $placed[0]->owner);
            self::assertSame($detail, $placed[0]->detail);
        }
    }

    /**
     * Guidance is its proposal, promoted - one fact, placed once.
     *
     * The moment guidance became durable is its source proposal's applied
     * moment. Emitting it as both a proposal event and a guidance event is the
     * same mistake as reading contract_approved from two owners.
     */
    public function testAppliedGuidanceIsPlacedOnceAndNoLongerListedAsUntimed(): void
    {
        $this->applicationWithCard();
        $this->writeFinding('finding.2026-03-01.001', '2026-03-01T08:00:00+00:00');
        $this->writeProposal('proposal.2026-03-01.003', 'applied', 'finding.2026-03-01.001', [
            'approved_by' => 'maintainer',
            'approved_at' => '2026-03-01T11:00:00+00:00',
            'applied_by' => 'maintainer',
            'applied_at' => '2026-03-03T10:00:00+00:00',
        ]);

        $activity = $this->activityFor('APP-1');

        $applied = $this->eventsOfKind($activity->events, 'proposal_applied');
        self::assertCount(1, $applied);
        self::assertSame('2026-03-03T10:00:00+00:00', $applied[0]->at);
        self::assertSame(
            [],
            array_values(array_filter($activity->untimed, static fn(UntimedOwnerFact $f) => $f->kind === 'guidance')),
            'agent-learning now dates this guidance, so it has no business in the untimed list',
        );
    }

    /**
     * Approved but not yet applied is a transition that has not happened, not a missing time.
     */
    public function testApprovedGuidanceNotYetAppliedIsNeitherPlacedNorListed(): void
    {
        $this->applicationWithCard();
        $this->writeFinding('finding.2026-03-01.001', '2026-03-01T08:00:00+00:00');
        $this->writeProposal('proposal.2026-03-01.004', 'approved', 'finding.2026-03-01.001', [
            'approved_by' => 'maintainer',
            'approved_at' => '2026-03-01T11:00:00+00:00',
        ]);

        $activity = $this->activityFor('APP-1');

        self::assertCount(1, $this->eventsOfKind($activity->events, 'proposal_approved'));
        self::assertSame([], $this->eventsOfKind($activity->events, 'proposal_applied'));
        self::assertSame(
            [],
            array_values(array_filter($activity->untimed, static fn(UntimedOwnerFact $f) => $f->kind === 'guidance')),
        );
    }

    /**
     * Applied with no applied time is the one guidance fact the owner cannot date - so it is listed.
     */
    public function testAppliedGuidanceWithoutAnAppliedTimeIsListedRatherThanDropped(): void
    {
        $this->applicationWithCard();
        $this->writeFinding('finding.2026-03-01.001', '2026-03-01T08:00:00+00:00');
        $this->writeProposal('proposal.2026-03-01.005', 'applied', 'finding.2026-03-01.001', [
            'approved_by' => 'maintainer',
            'approved_at' => '2026-09-01T11:00:00+00:00',
        ]);

        $activity = $this->activityFor('APP-1');

        self::assertSame([], $this->eventsOfKind($activity->events, 'proposal_applied'));
        $guidance = array_values(array_filter($activity->untimed, static fn(UntimedOwnerFact $f) => $f->kind === 'guidance'));
        self::assertCount(1, $guidance);
        self::assertStringContainsString('no applied time', $guidance[0]->missing);
    }

    /**
     * The timeline composes from the snapshot the page already read.
     *
     * A structural assertion because the cost it guards is invisible in output:
     * the page rendered identically whether the audit gateway was read once or
     * twice, and twice meant a second set of owner store reads per request.
     */
    public function testTheComposerDoesNotReadTheAuditTrailItself(): void
    {
        $parameters = (new \ReflectionClass(TaskActivityComposer::class))->getConstructor()?->getParameters() ?? [];
        $types = array_map(static fn(\ReflectionParameter $p) => (string) $p->getType(), $parameters);

        self::assertNotContains(AuditTrailGateway::class, $types);
    }

    private function activityWithCardAndContract(): TaskActivity
    {
        $app = $this->applicationWithCard();
        $this->proposeContract($app, (new CsrfTokenManager())->token(), 'Fixture goal');

        return $this->activityFor('APP-1');
    }

    private function composer(): TaskActivityComposer
    {
        return new TaskActivityComposer(
            new HumanDecisionGateway($this->root),
            new LearningCatalogGateway($this->root),
            new BoardProjectionGateway($this->root),
        );
    }

    private function activityFor(string $taskId): TaskActivity
    {
        return $this->composer()->forTask((new AuditTrailGateway($this->root))->task($taskId));
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
