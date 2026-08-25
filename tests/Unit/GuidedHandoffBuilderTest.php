<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Handoff\GuidedHandoffBuilder;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;

final class GuidedHandoffBuilderTest extends TestCase
{
    public function testCarriesCanonicalNextActionWithoutReplacingIt(): void
    {
        $card = $this->card('LOOP-280');
        $workflow = $this->workflow(
            'LOOP-280',
            'host_work',
            'perform the approved host-native implementation for LOOP-280 before calling agent-loop finish LOOP-280',
        );

        $handoff = (new GuidedHandoffBuilder())->build($card, $workflow);

        self::assertSame($workflow->nextAction, $handoff->nextAction);
        self::assertSame($workflow->nextActionKind, $handoff->nextActionKind);
        self::assertStringContainsString('next_action_kind: host_work', $handoff->prompt);
        self::assertStringContainsString('next_action: ' . $workflow->nextAction, $handoff->prompt);
        self::assertStringContainsString('file changes alone are not workflow progress', $handoff->prompt);
    }

    public function testDecisionRequiredPromptExplicitlyPreservesHumanAuthority(): void
    {
        $workflow = $this->workflow('LOOP-280', 'decision_required', 'agent-loop workflow approve LOOP-280 --by <named-actor>');

        $handoff = (new GuidedHandoffBuilder())->build($this->card('LOOP-280'), $workflow);

        self::assertStringContainsString('If it requires human authority, stop', $handoff->prompt);
        self::assertStringContainsString($workflow->nextAction, $handoff->prompt);
    }

    public function testRejectsCrossTaskProjection(): void
    {
        $this->expectException(LogicException::class);
        (new GuidedHandoffBuilder())->build(
            $this->card('LOOP-280'),
            $this->workflow('LOOP-281', 'host_work', 'implement'),
        );
    }

    private function card(string $taskId): CardSnapshot
    {
        return new CardSnapshot(
            $taskId,
            'Fix owner boundary',
            'DOING',
            'active',
            'Keep mutation authority inside agent-loop.',
            '',
            '',
            1,
            'codex',
            '',
        );
    }

    private function workflow(string $taskId, string $kind, string $action): WorkflowSnapshot
    {
        return new WorkflowSnapshot(
            $taskId,
            'run:' . $taskId,
            'governed',
            'incomplete',
            [],
            [],
            $action,
            $kind,
        );
    }
}
