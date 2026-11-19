<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\ExecutionBinding;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProjection;
use voku\AgentLoopRunner\Application\RunnerStatus;
use voku\AgentLoopRunner\Runtime\AttemptStatus;
use voku\AgentLoopRunner\Runtime\RuntimeAttempt;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerSnapshot;

final class RunnerSnapshotTest extends TestCase
{
    public function testKeepsOwnerAuthorityAndRunnerObservationVisiblySeparate(): void
    {
        $authority = new ExecutionProjection('TASK-1', 'run:TASK-1', 1, ExecutionProfileName::SURGICAL, 'sha256:plan', 'implementation', 2, null, [], 'abc');
        $observation = new RuntimeAttempt('TASK-1', 'run:TASK-1', 1, 'sha256:plan', 'implementation', 2, 'codex', 'sha256:workspace', 'submission-1', AttemptStatus::Prepared);

        $snapshot = RunnerSnapshot::fromStatus(new RunnerStatus($authority, $observation));

        self::assertTrue($snapshot->installed);
        self::assertSame('surgical', $snapshot->profile);
        self::assertSame('implementation', $snapshot->currentStageId);
        self::assertSame('codex', $snapshot->hostId);
        self::assertSame('prepared', $snapshot->observationStatus);
        self::assertTrue($snapshot->allowRun);
        self::assertTrue($snapshot->allowResume);
        self::assertFalse($snapshot->allowCancel);
    }

    public function testRunnerObservationCannotAdvanceCompletedLoopAuthority(): void
    {
        $authority = new ExecutionProjection('TASK-1', 'run:TASK-1', 1, ExecutionProfileName::SURGICAL, 'sha256:plan', null, 3, null, [], 'abc');
        $observation = new RuntimeAttempt(
            'TASK-1',
            'run:TASK-1',
            1,
            'sha256:plan',
            'implementation',
            2,
            'codex',
            'sha256:workspace',
            'submission-1',
            AttemptStatus::ProcessStarted,
            process: ['pid' => 4242, 'process_fingerprint' => 'sha256:process'],
        );

        $status = new RunnerStatus($authority, $observation);
        $snapshot = RunnerSnapshot::fromStatus($status);

        self::assertTrue($snapshot->complete);
        self::assertFalse($snapshot->allowRun);
        self::assertFalse($snapshot->allowResume);
        self::assertSame($status->allows(RunnerStatus::CANCEL), $snapshot->allowCancel, 'Cancel mirrors Runner capability without granting workflow authority.');
    }

    public function testJournalObservationOfCurrentAuthorityStaysCurrent(): void
    {
        $snapshot = RunnerSnapshot::fromObservation($this->attempt('run:TASK-1:current'))
            ->boundTo(new ExecutionBinding('run:TASK-1:current', 1, 'sha256:plan'));

        self::assertSame('process_started', $snapshot->observationStatus);
        self::assertSame('implementation', $snapshot->observationStageId);
    }

    /** @return iterable<string, array{ExecutionBinding}> */
    public static function otherAuthority(): iterable
    {
        yield 'other Run' => [new ExecutionBinding('run:TASK-1:replacement', 1, 'sha256:plan')];
        yield 'revised Contract, same Run id' => [new ExecutionBinding('run:TASK-1:current', 2, 'sha256:plan')];
        yield 'other plan' => [new ExecutionBinding('run:TASK-1:current', 1, 'sha256:other-plan')];
    }

    #[DataProvider('otherAuthority')]
    public function testJournalObservationOfOtherAuthorityIsStale(ExecutionBinding $current): void
    {
        $snapshot = RunnerSnapshot::fromObservation($this->attempt('run:TASK-1:current'))->boundTo($current);

        self::assertSame(RunnerSnapshot::OBSERVATION_STALE, $snapshot->observationStatus);
        self::assertSame('implementation', $snapshot->observationStageId, 'Stale evidence stays inspectable.');
        self::assertFalse($snapshot->allowRun);
        self::assertFalse($snapshot->allowResume);
        self::assertFalse($snapshot->allowCancel);
    }

    public function testJournalObservationWithoutProvableAuthorityIsUnverified(): void
    {
        $snapshot = RunnerSnapshot::fromObservation($this->attempt('run:TASK-1:current'))->boundTo(null);

        self::assertSame(RunnerSnapshot::OBSERVATION_UNVERIFIED, $snapshot->observationStatus);
    }

    public function testMissingJournalObservationIsNotInvented(): void
    {
        $snapshot = RunnerSnapshot::fromObservation(null)->boundTo(null);

        self::assertNull($snapshot->observationStatus);
        self::assertNull($snapshot->observationRunId);
    }

    public function testNotInstalledProjectsNoManagedControls(): void
    {
        $snapshot = RunnerSnapshot::notInstalled();

        self::assertFalse($snapshot->installed);
        self::assertFalse($snapshot->allowRun);
        self::assertFalse($snapshot->allowResume);
        self::assertFalse($snapshot->allowCancel);
    }

    private function attempt(string $runId): RuntimeAttempt
    {
        return new RuntimeAttempt('TASK-1', $runId, 1, 'sha256:plan', 'implementation', 2, 'codex', 'sha256:workspace', 'submission-1', AttemptStatus::ProcessStarted);
    }

    /** @return iterable<string, array{AttemptStatus, bool, bool}> */
    public static function runnerAttemptStatuses(): iterable
    {
        foreach (AttemptStatus::cases() as $status) {
            yield $status->value => [
                $status,
                $status === AttemptStatus::ProcessStarted,
                in_array($status, [AttemptStatus::Failed, AttemptStatus::Cancelled], true),
            ];
        }
    }

    #[DataProvider('runnerAttemptStatuses')]
    public function testClassifiesEveryRunnerAttemptStatusFromTheOwnerEnum(AttemptStatus $status, bool $running, bool $interrupted): void
    {
        $attempt = new RuntimeAttempt('TASK-1', 'run:TASK-1', 1, 'sha256:plan', 'implementation', 1, 'codex', 'sha256:workspace', 'submission-1', $status);
        $current = RunnerSnapshot::fromObservation($attempt)->boundTo(new ExecutionBinding('run:TASK-1', 1, 'sha256:plan'));

        self::assertSame($status->value, $current->observationStatus);
        self::assertSame($running, $current->processRunning());
        self::assertSame($interrupted, $current->attemptInterrupted());

        foreach ([new ExecutionBinding('run:TASK-1:other', 1, 'sha256:plan'), null] as $notCurrent) {
            $bound = RunnerSnapshot::fromObservation($attempt)->boundTo($notCurrent);
            self::assertFalse($bound->processRunning(), 'A stale or unverified attempt is never running.');
            self::assertFalse($bound->attemptInterrupted(), 'A stale or unverified attempt is never current interruption.');
        }
    }

    public function testNoRunnerObservationIsNeitherRunningNorInterrupted(): void
    {
        foreach ([RunnerSnapshot::notInstalled(), RunnerSnapshot::diagnostic('journal unreadable'), RunnerSnapshot::fromObservation(null)] as $snapshot) {
            self::assertFalse($snapshot->processRunning());
            self::assertFalse($snapshot->attemptInterrupted());
        }
    }
}
