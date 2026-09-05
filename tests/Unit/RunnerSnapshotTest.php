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

        $snapshot = RunnerSnapshot::fromStatus(new RunnerStatus($authority, $observation));

        self::assertTrue($snapshot->complete);
        self::assertFalse($snapshot->allowRun);
        self::assertFalse($snapshot->allowResume);
        self::assertTrue($snapshot->allowCancel, 'Cancel remains Runner observation control, not workflow authority.');
    }

    public function testNotInstalledProjectsNoManagedControls(): void
    {
        $snapshot = RunnerSnapshot::notInstalled();

        self::assertFalse($snapshot->installed);
        self::assertFalse($snapshot->allowRun);
        self::assertFalse($snapshot->allowResume);
        self::assertFalse($snapshot->allowCancel);
    }
}
