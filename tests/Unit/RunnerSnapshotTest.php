<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
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

    public function testJournalObservationOfCurrentRunStaysCurrent(): void
    {
        $snapshot = RunnerSnapshot::fromObservation($this->attempt('run:TASK-1:current'))->boundToRun('run:TASK-1:current');

        self::assertSame('process_started', $snapshot->observationStatus);
        self::assertSame('implementation', $snapshot->observationStageId);
        self::assertSame('run:TASK-1:current', $snapshot->observationRunId);
    }

    public function testJournalObservationOfAnotherRunIsStale(): void
    {
        $snapshot = RunnerSnapshot::fromObservation($this->attempt('run:TASK-1:retired'))->boundToRun('run:TASK-1:current');

        self::assertSame(RunnerSnapshot::OBSERVATION_STALE, $snapshot->observationStatus);
        self::assertSame('implementation', $snapshot->observationStageId, 'Stale evidence stays inspectable.');
        self::assertFalse($snapshot->allowRun);
        self::assertFalse($snapshot->allowResume);
        self::assertFalse($snapshot->allowCancel);
    }

    public function testJournalObservationWithoutCurrentLoopRunIsStale(): void
    {
        $snapshot = RunnerSnapshot::fromObservation($this->attempt('run:TASK-1:retired'))->boundToRun(null);

        self::assertSame(RunnerSnapshot::OBSERVATION_STALE, $snapshot->observationStatus);
    }

    public function testMissingJournalObservationIsNotInventedAsStale(): void
    {
        $snapshot = RunnerSnapshot::fromObservation(null)->boundToRun(null);

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
}
