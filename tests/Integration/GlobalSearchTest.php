<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Feature\Search\GlobalSearch;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentMap\CodeSearchGateway;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * One search box over several owners, which is only worth having if it cannot lie.
 *
 * The failure modes that matter are all of one kind: an answer that sounds
 * stronger than what was asked. "No matches" from an owner that could not be
 * asked, "no matches" from an index the owner calls stale, a count that hides
 * that the list was cut. Each is asserted from the rendered page, because that
 * is the only place a developer meets them.
 */
final class GlobalSearchTest extends TestCase
{
    private MapFixture $fixture;
    private string $root;
    private string $templates;

    protected function setUp(): void
    {
        $this->fixture = new MapFixture('agent_ui_search_');
        $this->root = $this->fixture->root;
        $this->templates = dirname(__DIR__, 2) . '/templates';

        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testTasksAreFoundByIdOrTitleAndLinkToTheirExistingPage(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system', 'APP-2' => 'Refactor billing']);

        $page = $this->search($app, 'login');

        self::assertStringContainsString('href="/task/APP-1"', $page);
        self::assertStringNotContainsString('href="/task/APP-2"', $page);
        self::assertStringContainsString('matched in title', $page);
        self::assertStringContainsString('agent-kanban', $page);
        self::assertStringContainsString('href="/task/APP-2"', $this->search($app, 'APP-2'));
    }

    /**
     * A project can hold several boards; the default one is only one of them.
     *
     * Searching just the default board would answer "no such task" for a task
     * that exists on a board the developer was not looking at.
     */
    public function testATaskOnAnotherBoardIsFoundAndTheBoardIsNamed(): void
    {
        mkdir($this->root . '/.agent-loop/todo/jira', 0o775, true);
        mkdir($this->root . '/.agent-loop/todo/followups', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/todo/kanban.config.json', json_encode([
            'defaultBoard' => 'jira',
            'boards' => [
                ['id' => 'jira', 'title' => 'Jira Tasks', 'projectPrefix' => 'JIRA', 'cardDirectory' => 'todo/jira'],
                ['id' => 'followups', 'title' => 'Followups', 'projectPrefix' => 'FOL', 'cardDirectory' => 'todo/followups'],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/.agent-loop/todo/jira/JIRA-1.md', "# JIRA-1 — Default board work\n\n- **Lane:** BACKLOG\n- **Status:** todo\n");
        file_put_contents($this->root . '/.agent-loop/todo/followups/FOL-1.md', "# FOL-1 — Followup billing cleanup\n\n- **Lane:** READY\n- **Status:** todo\n- **Task brief:** Brief\n");
        $app = new Application($this->root, $this->templates);

        $page = $this->search($app, 'billing');

        self::assertStringContainsString('href="/task/FOL-1"', $page, 'the non-default board must be searched too');
        self::assertStringContainsString('board Followups', $page);
        self::assertStringNotContainsString('href="/task/JIRA-1"', $page);
        // Both boards answer a search for a shared word, each hit naming its board.
        $both = $this->search($app, 'task 1');
        self::assertStringContainsString('href="/task/JIRA-1"', $both);
        self::assertStringContainsString('href="/task/FOL-1"', $both);
    }

    public function testEveryWordMustMatchNotJustOne(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system', 'APP-2' => 'Refactor billing']);

        self::assertStringContainsString('href="/task/APP-1"', $this->search($app, 'build login'));
        self::assertStringNotContainsString('href="/task/', $this->search($app, 'login billing'));
    }

    public function testAScopeNarrowsTheSearchAndTheSlashIsOptional(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);
        $this->writeFinding('finding.2026-03-01.001', 'APP-1', 'Login lessons were learned.');

        foreach (['task login', '/task login'] as $typed) {
            $page = $this->search($app, $typed);
            self::assertStringContainsString('href="/task/APP-1"', $page, $typed);
            self::assertStringNotContainsString('/knowledge/findings/', $page, $typed);
        }
        $combined = $this->search($app, 'login');
        self::assertStringContainsString('href="/task/APP-1"', $combined);
        self::assertStringContainsString('/knowledge/findings/finding.2026-03-01.001', $combined);
    }

    /**
     * No Learning root is not "no Findings".
     *
     * agent-learning answers both with an empty list. A page that took the empty
     * list at face value would tell a developer in an unconfigured project that
     * Learning has nothing to say about their term.
     */
    public function testASourceThatCouldNotBeAskedIsNeverReportedAsNoMatches(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, 'login');

        self::assertStringContainsString('Unavailable — not searched.', $page);
        self::assertStringContainsString('has no Learning root', $page);
        self::assertStringContainsString('This is not the same as no matches.', $page);
        // Nor does it hide in the folded "searched, no matches" list.
        $folded = $this->section($page, 'search-empty');
        self::assertStringNotContainsString('Findings', $folded);
        self::assertStringNotContainsString('Proposals', $folded);
    }

    public function testASourceThatWasAskedAndFoundNothingSaysSoUnderItsOwner(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);
        $this->writeFinding('finding.2026-03-01.001', 'APP-1', 'Unrelated observation.');

        $page = $this->search($app, 'login');

        $folded = $this->section($page, 'search-empty');
        self::assertStringContainsString('Findings', $folded);
        self::assertStringContainsString('agent-learning', $folded);
        // Asked and empty is not a panel of its own, and certainly not an unavailable one.
        self::assertStringNotContainsString('id="search-finding"', $page);
    }

    /**
     * One owner's failure is that owner's answer; the page and the others carry on.
     */
    public function testABrokenOwnerIsShownWithItsReasonAndTheOthersStillAnswer(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);
        $this->writeFinding('finding.2026-03-01.001', 'APP-1', 'Login lessons were learned.');
        file_put_contents($this->root . '/.agent-loop/learning/findings/validated/finding.2026-03-01.002.json', '{ not json');

        $response = $app->handle(new Request('GET', '/search', query: ['q' => 'login']));
        $page = $response->body;

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/task/APP-1"', $page, 'tasks must still answer');
        self::assertStringContainsString('Unavailable — not searched.', $page);
        self::assertStringContainsString('finding.2026-03-01.002', $page, 'the owner\'s own message is shown, not swallowed');
    }

    public function testATruncatedListReportsTheTrueMatchedCountAndHowToSeeMore(): void
    {
        $cards = [];
        for ($index = 1; $index <= GlobalSearch::COMBINED_LIMIT + 2; $index++) {
            $cards['APP-' . $index] = 'Shared theme item ' . $index;
        }
        $app = $this->applicationWithCards($cards);

        $page = $this->search($app, 'shared theme');

        $total = GlobalSearch::COMBINED_LIMIT + 2;
        self::assertStringContainsString('Showing ' . GlobalSearch::COMBINED_LIMIT . ' of ' . $total, $page);
        self::assertSame(GlobalSearch::COMBINED_LIMIT, substr_count($page, 'class="search-hit"'));
        self::assertStringContainsString('/search?q=task%20shared%20theme', $page);

        $scoped = $this->search($app, 'task shared theme');
        self::assertSame($total, substr_count($scoped, 'class="search-hit"'));
        self::assertStringContainsString($total . ' matches.', $scoped);
    }

    /**
     * "Search only tasks" on a search that is already only tasks links to itself.
     *
     * The advice cannot work: the scoped query is the query the developer is
     * already looking at, with the same limit. A scoped list that is cut says so
     * and tells the developer the one thing that can help - narrow the term.
     */
    public function testAScopedTruncatedListDoesNotOfferALinkBackToItself(): void
    {
        $cards = [];
        for ($index = 1; $index <= GlobalSearch::SCOPED_LIMIT + 3; $index++) {
            $cards['APP-' . $index] = 'Shared theme item ' . $index;
        }
        $app = $this->applicationWithCards($cards);

        $page = $this->search($app, 'task shared theme');

        self::assertStringContainsString('Showing ' . GlobalSearch::SCOPED_LIMIT . ' of ' . (GlobalSearch::SCOPED_LIMIT + 3), $page);
        self::assertStringNotContainsString('search only', $page);
        self::assertStringNotContainsString('/search?q=task%20shared%20theme', $page);
        self::assertStringContainsString('narrow the term', $page);
    }

    /**
     * The Code panel names what agent-map said, not the identifier it filed it under.
     *
     * agent-map gives a machine reason (`map_missing`) and a readable failure
     * beside it. Showing only the code leaves a developer to look up what it
     * means; the readable sentence comes first and the code stays beside it as
     * the owner's own identifier.
     */
    public function testAnUnavailableCodeIndexShowsTheOwnersReadableFailureAndKeepsItsCode(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);
        $readiness = (new CodeSearchGateway($this->root))->readiness();
        self::assertNotNull($readiness->failure, 'the fixture must leave agent-map with something to say');
        self::assertNotNull($readiness->reason);

        $section = $this->section($this->search($app, 'code login'), 'search-code');

        self::assertStringContainsString(htmlspecialchars($readiness->failure, ENT_QUOTES), $section);
        self::assertStringContainsString($readiness->reason, $section, 'the owner\'s identifier stays visible beside the sentence');
        self::assertLessThan(
            (int) strpos($section, $readiness->reason),
            (int) strpos($section, htmlspecialchars($readiness->failure, ENT_QUOTES)),
            'the readable sentence leads',
        );
    }

    /**
     * Whether a Proposal is guidance is agent-learning's projection to answer.
     */
    public function testGuidanceIsWhateverAgentLearningProjectsAndLinksToBothViews(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);
        $this->writeFinding('finding.2026-03-01.001', 'APP-1', 'Login lessons were learned.');
        $this->writeProposal('proposal.2026-03-01.001', 'approved', 'finding.2026-03-01.001', [
            'target' => 'login-skill',
            'approved_by' => 'maintainer',
            'approved_at' => '2026-03-02T10:00:00+00:00',
        ]);
        $this->writeProposal('proposal.2026-03-01.002', 'acknowledged', 'finding.2026-03-01.001', [
            'action' => 'NO_DURABLE_LEARNING',
            'target_type' => null,
            'target' => null,
            'reason' => 'login handled elsewhere',
            'acknowledged_by' => 'reviewer',
            'acknowledged_at' => '2026-03-02T11:00:00+00:00',
        ]);

        // A target type that is not a guidance type: a record the owner holds and
        // does not project as guidance. A rule keyed on "has a target type" would
        // wrongly call it guidance; only asking the owner gets it right.
        $this->writeProposal('proposal.2026-03-01.003', 'candidate', 'finding.2026-03-01.001', [
            'target_type' => 'file',
            'target' => 'docs/login.md',
        ]);

        $guidance = $this->search($app, 'guidance login');
        self::assertStringContainsString('href="/knowledge/guidance/proposal.2026-03-01.001"', $guidance);
        self::assertStringContainsString('href="/knowledge/proposals/proposal.2026-03-01.001"', $guidance);
        self::assertStringNotContainsString('proposal.2026-03-01.002', $guidance, 'not guidance, so not listed as guidance');
        self::assertStringNotContainsString('proposal.2026-03-01.003', $guidance, 'has a target type, but the owner does not project it as guidance');

        $combined = $this->search($app, 'proposal login');
        self::assertStringContainsString('href="/knowledge/proposals/proposal.2026-03-01.001"', $combined);
        self::assertStringContainsString('href="/knowledge/guidance/proposal.2026-03-01.001"', $combined, 'a proposal that is also guidance says so');
        self::assertStringContainsString('href="/knowledge/proposals/proposal.2026-03-01.002"', $combined);
        self::assertStringContainsString('href="/knowledge/proposals/proposal.2026-03-01.003"', $combined);
        self::assertSame(1, substr_count($combined, '>Guidance</a>'), 'only the one the owner projects as guidance');
    }

    public function testAnIndexTheOwnerCallsStaleStillAnswersButSaysItMayBeIncomplete(): void
    {
        // The index goes in first: the Map locator chooses its root when the
        // Application is built, and the real entry point builds one per request.
        $this->indexedClass('OrderProcessor');
        $hit = 'href="/map/symbol?id=class%3AOrderProcessor"';

        $fresh = $this->search($this->applicationWithCards(['APP-1' => 'Build login system']), 'symbol OrderProcessor');
        self::assertStringContainsString($hit, $fresh, 'the hit itself, not just the query echoed back');
        self::assertStringNotContainsString('Possibly incomplete', $fresh);

        // The source moves on after the map was built.
        file_put_contents($this->root . '/src/OrderProcessor.php', "<?php\n// edited after indexing\nfinal class OrderProcessor {}\n");
        $app = new Application($this->root, $this->templates);

        $stale = $this->search($app, 'symbol OrderProcessor');
        self::assertStringContainsString($hit, $stale, 'stale is not broken: it still answers');
        self::assertStringContainsString('Possibly incomplete.', $stale);
        self::assertStringContainsString('index as stale', $stale);

        $none = $this->search($app, 'symbol NothingLikeThis');
        self::assertStringNotContainsString('class%3A', $none);
        $folded = $this->section($none, 'search-empty');
        self::assertStringContainsString('Symbols', $folded);
        self::assertStringContainsString('possibly incomplete', $folded, '"no matches" from a stale index is a weaker claim');
    }

    /**
     * agent-map caps what it returns, so this page asks for one more than it shows.
     *
     * That extra record is the only way to say "there is more" without being
     * handed the whole index - and the count it gives is a lower bound, which the
     * page must not print as if it were the total.
     */
    public function testSymbolTruncationIsReportedAsALowerBoundNotATotal(): void
    {
        $this->indexedClasses('Widget', GlobalSearch::COMBINED_LIMIT + 2);
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, 'Widget');

        self::assertStringContainsString('Showing ' . GlobalSearch::COMBINED_LIMIT . ' of more than ' . GlobalSearch::COMBINED_LIMIT, $page);
        self::assertSame(GlobalSearch::COMBINED_LIMIT, substr_count($this->section($page, 'search-symbol'), 'class="search-hit"'));
        self::assertStringContainsString('/search?q=symbol%20Widget', $page);
    }

    public function testExactlyAsManySymbolsAsTheLimitIsNotCalledTruncated(): void
    {
        $this->indexedClasses('Widget', GlobalSearch::COMBINED_LIMIT);
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, 'Widget');

        self::assertStringContainsString(GlobalSearch::COMBINED_LIMIT . ' matches.', $page);
        self::assertStringNotContainsString('Showing', $page);
    }

    public function testMissingCodeIndexIsUnavailableNotEmpty(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, 'symbol OrderProcessor');

        self::assertStringContainsString('Unavailable — not searched.', $page);
        self::assertStringContainsString('agent-map', $page);
    }

    /**
     * Chunk search returns the symbols the combined view already shows, with previews.
     *
     * Running both would put one record on the page twice under two owners' names
     * for the same fact, so chunks are searched only when asked for - and the
     * combined view says where to ask.
     */
    public function testCodeChunksAreSearchedOnlyWhenAskedFor(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $combined = $this->search($app, 'login');
        self::assertStringNotContainsString('id="search-code"', $combined);
        self::assertStringContainsString('Code chunks are not searched in the combined view', $combined);
        self::assertStringContainsString('/search?q=code%20login', $combined);

        $code = $this->search($app, 'code login');
        self::assertStringContainsString('id="search-code"', $code);
        self::assertStringNotContainsString('id="search-task"', $code);
    }

    public function testCodeScopesAskForATermInsteadOfListingTheIndex(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, 'symbol');

        self::assertStringContainsString('needs something to look for', $page);
        self::assertStringNotContainsString('search-hit', $page);
    }

    public function testPromptRecipesAreSearchedAndSendYouToTheWorkbench(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, 'prompt review');

        self::assertStringContainsString('agent-recall-compiler', $page);
        self::assertStringContainsString('href="/prompts"', $page);
        self::assertStringContainsString('select it on the Prompt Workbench', $page);
    }

    /**
     * What was typed comes back into the page, so it must not be able to become markup.
     */
    public function testTheQueryIsEscapedWhereverItIsEchoed(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);
        $payload = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

        $page = $this->page($app, $payload);

        self::assertStringNotContainsString('<script>alert(1)</script>', $page);
        self::assertStringNotContainsString('<img src=x', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        // The header form echoes it too, inside an attribute.
        self::assertStringNotContainsString('value=""><script>', $page);
    }

    public function testAQueryTooLongToHaveBeenMeantIsRefusedNotSearched(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, str_repeat('login ', 60));

        self::assertStringContainsString('was not searched', $page);
        self::assertStringNotContainsString('search-hit', $page);
    }

    public function testNothingIsSearchedUntilSomethingIsTyped(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->search($app, '');

        self::assertStringContainsString('Nothing is searched until you do.', $page);
        self::assertStringNotContainsString('search-group', $page);
    }

    /**
     * A search changes nothing, so it is GET-only: no CSRF to forget, nothing to resubmit.
     */
    public function testSearchIsReadOnlyAndAnswersGetOnly(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $post = $app->handle(new Request('POST', '/search', body: ['q' => 'login', '_csrf' => (new CsrfTokenManager())->token()]));

        self::assertNotSame(200, $post->status);
    }

    /**
     * The entry point has to exist where a developer is, with scripts off.
     */
    public function testEveryPageCarriesTheNoScriptSearchForm(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        foreach (['/', '/board', '/task/APP-1', '/knowledge', '/map', '/prompts', '/commands', '/setup', '/search'] as $path) {
            $page = $app->handle(new Request('GET', $path))->body;
            self::assertStringContainsString('<form class="masthead-search" role="search" action="/search" method="get">', $page, $path);
            self::assertStringContainsString('<label class="visually-hidden" for="global-search">', $page, $path);
            self::assertStringContainsString('name="q"', $page, $path);
        }
    }

    public function testTheHeaderFormKeepsWhatWasSearchedSoTheQueryIsTheUrl(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->page($app, 'task APP-1');

        self::assertSame(2, substr_count($page, 'value="task APP-1"'), 'header form and page form both carry it');
    }

    /**
     * The `/` hint is only honest when the script that makes it work runs.
     */
    public function testTheShortcutHintShipsHiddenForTheScriptToReveal(): void
    {
        $app = $this->applicationWithCards(['APP-1' => 'Build login system']);

        $page = $this->page($app, 'login');

        self::assertMatchesRegularExpression('/<kbd class="masthead-search__hint" hidden aria-hidden="true">\/<\/kbd>/', $page);
    }

    /**
     * @param array<string, string> $cards
     */
    private function applicationWithCards(array $cards): Application
    {
        $app = new Application($this->root, $this->templates);
        foreach ($cards as $id => $title) {
            $created = $app->handle(new Request('POST', '/board/new', body: [
                '_csrf' => (new CsrfTokenManager())->token(),
                'card_id' => $id,
                'title' => $title,
                'lane' => 'BACKLOG',
                'status' => 'todo',
                'summary' => 'Fixture',
                'task_brief' => 'Fixture brief.',
                'validation' => 'composer test',
            ]));
            self::assertSame(303, $created->status, $id);
        }

        return $app;
    }

    /**
     * The search page's own content, without the inlined stylesheet and script.
     *
     * Those carry words like `search-group` and `search-hit` as selectors, so an
     * assertion that something is absent would be defeated by the stylesheet
     * that styles it.
     */
    private function search(Application $app, string $typed): string
    {
        $page = $this->page($app, $typed);
        $start = strpos($page, '<main');
        $end = strpos($page, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($page, $start, $end - $start);
    }

    /** The whole response, header included - for the parts that live outside <main>. */
    private function page(Application $app, string $typed): string
    {
        $response = $app->handle(new Request('GET', '/search', query: ['q' => $typed]));
        self::assertSame(200, $response->status, $typed);

        return $response->body;
    }

    /** The markup of one section, so a claim about it cannot be satisfied by another. */
    private function section(string $page, string $headingId): string
    {
        $start = strpos($page, 'id="' . $headingId . '"');
        self::assertNotFalse($start, 'no section ' . $headingId . ' on the page');
        $end = strpos($page, '</section>', $start);

        return substr($page, $start, ($end === false ? strlen($page) : $end) - $start);
    }

    private function indexedClass(string $name): void
    {
        $sha = $this->fixture->writeFile('src/' . $name . '.php', "<?php\nfinal class " . $name . " {}\n");
        $this->fixture->writeMap([[
            'path' => 'src/' . $name . '.php',
            'sha256' => $sha,
            'namespace' => '',
            'symbols' => [MapFixture::classSymbol($name, $name, 2, 2)],
        ]]);
    }

    private function indexedClasses(string $prefix, int $count): void
    {
        $files = [];
        for ($index = 1; $index <= $count; $index++) {
            $name = $prefix . $index;
            $sha = $this->fixture->writeFile('src/' . $name . '.php', "<?php\nfinal class " . $name . " {}\n");
            $files[] = [
                'path' => 'src/' . $name . '.php',
                'sha256' => $sha,
                'namespace' => '',
                'symbols' => [MapFixture::classSymbol($name, $name, 2, 2)],
            ];
        }
        $this->fixture->writeMap($files);
    }

    private function writeFinding(string $id, string $taskId, string $observation): void
    {
        $directory = $this->root . '/.agent-loop/learning/findings/validated';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true)) {
            throw new RuntimeException('Unable to create the learning fixture root.');
        }
        file_put_contents(
            $this->root . '/.agent-loop/learning/config.json',
            json_encode(['schema_version' => '1.0', 'task_id_pattern' => '^[A-Z]+-[0-9]+$'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        file_put_contents($directory . '/' . $id . '.json', json_encode([
            'id' => $id,
            'task_id' => $taskId,
            'session' => 'session_' . $taskId,
            'created_at' => '2026-03-01T08:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Example.php'],
            'observation' => $observation,
            'evidence' => [['type' => 'manual_verification', 'summary' => 'Reproduced in the fixture.']],
            'hypothesis' => 'It generalises.',
            'validated_conclusion' => 'It generalises, and here is when.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'internal',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * A proposal record in agent-learning's own shape, within its source finding's scope.
     *
     * @param array<string, mixed> $fields
     */
    private function writeProposal(string $id, string $status, string $findingId, array $fields = []): void
    {
        $directory = $this->root . '/.agent-loop/learning/proposals/' . $status;
        if (!is_dir($directory) && !mkdir($directory, 0o775, true)) {
            throw new RuntimeException('Unable to create the proposal fixture directory.');
        }
        file_put_contents($directory . '/' . $id . '.json', json_encode(array_merge([
            'id' => $id,
            'created_at' => '2026-03-01T09:00:00+00:00',
            'action' => 'REPLACE',
            'target_type' => 'skill',
            'target' => 'fixture-skill',
            'scope' => ['src/Example.php'],
            'source_findings' => [$findingId],
            'old' => 'Before.',
            'new' => 'After.',
            'reason' => 'The fixture needs a proposal about login.',
            'boundary' => 'Fixture only.',
            'validation' => ['Run the suite.'],
            'status' => $status,
            'proposed_by' => 'agent_alpha',
        ], $fields), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
}
