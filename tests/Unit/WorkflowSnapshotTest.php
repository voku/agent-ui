<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;

final class WorkflowSnapshotTest extends TestCase
{
    public function testNextActionIsDataNotDerivedState(): void
    {
        $snapshot = new WorkflowSnapshot(
            taskId: 'UI-1',
            runId: 'run-1',
            mode: 'default',
            state: 'blocked',
            references: ['approval' => ['state' => 'missing']],
            disagreements: [],
            nextAction: 'owner-projected command',
            nextActionKind: 'decision_required',
        );

        self::assertSame('owner-projected command', $snapshot->nextAction);
        self::assertSame('decision_required', $snapshot->nextActionKind);
    }
}
