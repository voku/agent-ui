<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Search\SearchQuery;

#[CoversClass(SearchQuery::class)]
final class SearchQueryTest extends TestCase
{
    /** @return array<string, array{string, ?string, string}> */
    public static function commands(): array
    {
        return [
            'scope and term' => ['task UI-58', 'task', 'UI-58'],
            'leading slash is optional' => ['/task UI-58', 'task', 'UI-58'],
            'scope is case-insensitive' => ['/Proposal provenance', 'proposal', 'provenance'],
            'multi-word term is kept whole' => ['guidance owner boundary', 'guidance', 'owner boundary'],
            'find means everywhere' => ['/find memory', null, 'memory'],
            'a bare scope browses that scope' => ['/task', 'task', ''],
            'symbol' => ['symbol WorkflowProgressProjector', 'symbol', 'WorkflowProgressProjector'],
        ];
    }

    #[DataProvider('commands')]
    public function testACommandSplitsIntoScopeAndTerm(string $raw, ?string $scope, string $term): void
    {
        $query = SearchQuery::parse($raw);

        self::assertSame($scope, $query->scope);
        self::assertSame($term, $query->term);
    }

    /**
     * Only a word this UI has a scope for narrows anything.
     *
     * `memory rules` looks like a command and is not one: reading it as a scope
     * called `memory` would answer a different question than the one asked.
     */
    public function testAnUnknownFirstWordIsPartOfTheTermNotAScope(): void
    {
        $query = SearchQuery::parse('memory rules');

        self::assertNull($query->scope);
        self::assertSame('memory rules', $query->term);
        self::assertSame(['memory', 'rules'], $query->words());
    }

    /**
     * A slash is a command marker only when a command follows it.
     *
     * `/etc/hosts` is a path someone is looking for; stripping its slash would
     * quietly search for `etc/hosts`.
     */
    public function testALeadingSlashThatIsNotACommandStaysPartOfThePath(): void
    {
        $query = SearchQuery::parse('/etc/hosts');

        self::assertNull($query->scope);
        self::assertSame('/etc/hosts', $query->term);
    }

    /** One slash marks a command; two do not, and neither is quietly collapsed into one. */
    public function testOnlyASingleLeadingSlashMarksACommand(): void
    {
        $query = SearchQuery::parse('//task UI-58');

        self::assertNull($query->scope);
        self::assertSame('//task UI-58', $query->term);
    }

    public function testTheScopeWordsAreTheOnlyOnesTheUiHasAScopeFor(): void
    {
        self::assertSame(['task', 'finding', 'proposal', 'guidance', 'symbol', 'code', 'prompt'], SearchQuery::SCOPES);
    }

    public function testNothingTypedIsEmptyAndSearchesNothing(): void
    {
        self::assertTrue(SearchQuery::parse('')->isEmpty());
        self::assertTrue(SearchQuery::parse('   ')->isEmpty());
        self::assertTrue(SearchQuery::parse('/find')->isEmpty());
        self::assertFalse(SearchQuery::parse('/task')->isEmpty());
    }

    public function testCodeScopesAskForATermInsteadOfListingTheIndex(): void
    {
        self::assertTrue(SearchQuery::parse('symbol')->needsTerm());
        self::assertTrue(SearchQuery::parse('/code')->needsTerm());
        self::assertFalse(SearchQuery::parse('symbol Foo')->needsTerm());
        self::assertFalse(SearchQuery::parse('task')->needsTerm());
    }

    public function testAQueryPastTheLimitIsRefusedNotTruncated(): void
    {
        $query = SearchQuery::parse(str_repeat('a', SearchQuery::MAXIMUM_LENGTH + 1));

        self::assertTrue($query->tooLong);
        self::assertSame('', $query->term);
        self::assertFalse(SearchQuery::parse(str_repeat('a', SearchQuery::MAXIMUM_LENGTH))->tooLong);
    }

    public function testWordsAreLowercasedAndSplitOnAnyWhitespace(): void
    {
        self::assertSame(['owner', 'boundary'], SearchQuery::parse("Owner \t Boundary")->words());
    }
}
