<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Integration\AgentLoop\DecisionCardSnapshot;

/**
 * A card the UI does not understand is no card, never a partial one.
 *
 * The decision card states what a human is about to authorize. Printing half of
 * it - say the subject without the "authorizes" boundary - would misstate the
 * decision, so any unexpected shape falls back to the typed forms alone.
 */
final class DecisionCardSnapshotTest extends TestCase
{
    public function testAWellFormedOwnerCardIsReadInOwnerOrder(): void
    {
        $card = DecisionCardSnapshot::fromOwnerArray(self::card());

        self::assertNotNull($card);
        self::assertSame('Decision required: Contract approval', $card->title);
        self::assertSame([
            ['label' => 'task_id', 'value' => 'APP-1'],
            ['label' => 'contract_revision', 'value' => '2'],
        ], $card->subject);
        self::assertSame('agent-loop workflow approve APP-1 --revision 2 --by <named-actor>', $card->choices[0]['command']);
        self::assertNull($card->choices[1]['command']);
        self::assertSame(['composer ci'], $card->plannedValidation);
    }

    public function testNonScalarSubjectFactsAreLeftOutRatherThanFlattened(): void
    {
        $raw = self::card();
        $raw['subject']['blockers'] = [['code' => 'x']];

        $card = DecisionCardSnapshot::fromOwnerArray($raw);

        self::assertNotNull($card);
        self::assertCount(2, $card->subject);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function malformedCards(): iterable
    {
        $c = self::card();
        unset($c['authorizes']);
        yield 'missing authorizes' => [$c];

        $c = self::card();
        $c['title'] = 3;
        yield 'non-string title' => [$c];

        $c = self::card();
        $c['may_change'] = ['a' => 'src'];
        yield 'non-list may_change' => [$c];

        $c = self::card();
        unset($c['choices'][0]['effect']);
        yield 'choice without effect' => [$c];

        $c = self::card();
        $c['choices'][0]['command'] = ['x'];
        yield 'non-string command' => [$c];

        $c = self::card();
        unset($c['proof']['validation']['state']);
        yield 'validation without state' => [$c];

        $c = self::card();
        $c['proof']['review']['detail'] = 1;
        yield 'non-string review detail' => [$c];
    }

    /** @param array<string, mixed> $card */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedCards')]
    public function testAnUnexpectedShapeYieldsNoCard(array $card): void
    {
        self::assertNull(DecisionCardSnapshot::fromOwnerArray($card));
    }

    /**
     * @return array{
     *     schema_version: string, type: string, language: string, requested_language: string, title: string,
     *     subject: array<string, mixed>, why_now: array<string, mixed>, may_change: list<string>, out_of_scope: list<string>,
     *     proof: array{validation: array<string, mixed>, review: array<string, mixed>},
     *     authorizes: string, choices: list<array<string, mixed>>, unknown: list<string>
     * }
     */
    private static function card(): array
    {
        return [
            'schema_version' => '1.0',
            'type' => 'contract_approval',
            'language' => 'en',
            'requested_language' => 'en',
            'title' => 'Decision required: Contract approval',
            'subject' => ['task_id' => 'APP-1', 'contract_revision' => 2],
            'why_now' => ['kind' => 'first_revision', 'summary' => 'Revision 1 is the first revision.', 'changes' => []],
            'may_change' => ['src'],
            'out_of_scope' => ['No new dependencies'],
            'proof' => [
                'validation' => ['state' => 'not_run', 'detail' => null, 'planned' => ['composer ci']],
                'review' => ['state' => 'not_run', 'detail' => null],
            ],
            'authorizes' => 'What approval authorizes: work only within the Contract scope.',
            'choices' => [
                ['id' => 'approve', 'label' => 'Approve', 'effect' => 'Work may begin.', 'command' => 'agent-loop workflow approve APP-1 --revision 2 --by <named-actor>'],
                ['id' => 'request_changes', 'label' => 'Request changes', 'effect' => 'Describe what must change.', 'command' => null],
            ],
            'unknown' => [],
        ];
    }
}
