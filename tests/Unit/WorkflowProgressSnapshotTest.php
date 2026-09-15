<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunProgressProjection;
use voku\AgentLoop\Run\RunProgressStep;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressSnapshot;

final class WorkflowProgressSnapshotTest extends TestCase
{
    public function testOwnerProgressFactsAreCopiedWithoutReclassification(): void
    {
        $owner = new RunProgressProjection(
            taskId: 'UI-36',
            state: 'blocked',
            nextAction: 'change the implementation so validation passes',
            nextActionKind: 'host_work',
            steps: [
                new RunProgressStep(
                    id: 'contract',
                    label: 'Contract',
                    status: RunProgressStep::STATUS_DONE,
                    owner: 'agent-loop',
                ),
                new RunProgressStep(
                    id: 'validation',
                    label: 'Validation',
                    status: RunProgressStep::STATUS_BLOCKED,
                    owner: 'agent-session',
                    reason: 'composer ci failed',
                ),
            ],
        );

        $snapshot = WorkflowProgressSnapshot::fromOwner($owner);

        self::assertSame('UI-36', $snapshot->taskId);
        self::assertSame('blocked', $snapshot->state);
        self::assertSame('host_work', $snapshot->nextActionKind);
        self::assertSame('change the implementation so validation passes', $snapshot->nextAction);
        self::assertCount(2, $snapshot->steps);
        self::assertSame(RunProgressStep::STATUS_DONE, $snapshot->steps[0]->status);
        self::assertSame(RunProgressStep::STATUS_BLOCKED, $snapshot->steps[1]->status);
        self::assertSame('agent-session', $snapshot->steps[1]->owner);
        self::assertSame('composer ci failed', $snapshot->steps[1]->reason);
    }
}
