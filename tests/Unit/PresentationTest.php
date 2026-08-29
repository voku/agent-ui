<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\View\Presentation;

#[CoversClass(Presentation::class)]
final class PresentationTest extends TestCase
{
    public function testUnknownOwnerStateStaysNeutralInsteadOfBeingGuessedAt(): void
    {
        self::assertSame(Presentation::TONE_NEUTRAL, Presentation::tone('a_state_no_owner_has_defined_yet'));
        self::assertSame(Presentation::TONE_NEUTRAL, Presentation::tone(null));
        self::assertSame(Presentation::TONE_NEUTRAL, Presentation::tone(''));
    }

    public function testKnownOwnerStatesMapToTheirTone(): void
    {
        self::assertSame(Presentation::TONE_OK, Presentation::tone('approved'));
        self::assertSame(Presentation::TONE_OK, Presentation::tone('ready_to_close'));
        self::assertSame(Presentation::TONE_ATTENTION, Presentation::tone('unacknowledged'));
        self::assertSame(Presentation::TONE_ATTENTION, Presentation::tone('incomplete'));
        self::assertSame(Presentation::TONE_BLOCKED, Presentation::tone('failed'));
        self::assertSame(Presentation::TONE_BLOCKED, Presentation::tone('invalid'));
    }

    public function testToneIsCaseInsensitiveBecauseOwnersRenderStatesBothWays(): void
    {
        self::assertSame(Presentation::TONE_OK, Presentation::tone('APPROVED'));
        self::assertSame(Presentation::TONE_OK, Presentation::tone('Approved'));
    }

    public function testAbsenceIsNeutralRatherThanAFailure(): void
    {
        foreach (['missing', 'none', 'unavailable', 'not_configured', 'pending_close'] as $state) {
            self::assertSame(Presentation::TONE_NEUTRAL, Presentation::tone($state), $state . ' is an ordinary absence');
        }
    }

    public function testLabelMakesOwnerTokensReadableWithoutRenamingThem(): void
    {
        self::assertSame('ready to close', Presentation::label('ready_to_close'));
        self::assertSame('unknown', Presentation::label(null));
        self::assertSame('approved', Presentation::label('approved'));
    }

    public function testNextActionKindHintExplainsHowToTreatTheAction(): void
    {
        self::assertStringContainsString('exactly as written', Presentation::nextActionKindHint('command'));
        self::assertStringContainsString('not a command', Presentation::nextActionKindHint('host_work'));
        self::assertStringContainsString('human decision', Presentation::nextActionKindHint('decision_required'));
        self::assertStringContainsString('Nothing further', Presentation::nextActionKindHint('none'));
    }

    public function testEveryActionKindAgentLoopEmitsHasItsOwnGloss(): void
    {
        $fallback = Presentation::nextActionKindHint('some_future_kind');

        foreach (['command', 'command_template', 'host_work', 'decision_required', 'none'] as $kind) {
            self::assertNotSame($fallback, Presentation::nextActionKindHint($kind), $kind . ' falls back');
        }
    }

    public function testAnUnknownActionKindDefersToAgentLoopRatherThanInventingGuidance(): void
    {
        self::assertStringContainsString('See agent-loop', Presentation::nextActionKindHint('some_future_kind'));
    }

    public function testReferenceAccessorsReadOwnerFieldsAndTolerateMalformedInput(): void
    {
        $reference = ['state' => 'approved', 'owner' => 'agent-loop'];

        self::assertSame('approved', Presentation::referenceState($reference));
        self::assertSame('agent-loop', Presentation::referenceOwner($reference));

        self::assertNull(Presentation::referenceState('not-an-array'));
        self::assertNull(Presentation::referenceOwner([]));
        self::assertNull(Presentation::referenceState(['state' => 42]));
    }
}
