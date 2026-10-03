<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\TaskTransparencyGateway;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * The page a human decides on must render in the state where a decision is owed.
 *
 * Reaching "acknowledge this review report" takes a real governed run: an approved
 * Contract, `enter`, work inside scope, and `finish` producing the owner's review
 * report. The report then carries findings, and the task page printed each one by
 * reading `->severity->value` - but agent-loop projects the severity as a string, so
 * the first review with a single finding turned the whole task page into a 500 at the
 * moment the human was asked to act. No earlier test reached this state, because none
 * of them ran a governed task far enough to have a review report.
 *
 * The findings are read back from the owner's own projection rather than written into
 * the test, so the assertion is "the page shows what agent-loop reported", not "the
 * page shows the words this test happened to choose".
 */
final class ReviewAcknowledgementDecisionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-review-ack-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o775, true) || !mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create the governed-run fixture root.');
        }
        copy(dirname(__DIR__, 2) . '/composer.json', $this->root . '/composer.json');
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
        file_put_contents($this->root . '/src/Greeter.php', "<?php\nfinal class Greeter { public function hi(): string { return 'hi'; } }\n");
        $this->git('init -q . && git config user.email t@example.test && git config user.name t && git add -A && git commit -q -m base');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTheTaskPageRendersTheReviewFindingsTheHumanIsAskedToAcknowledge(): void
    {
        $app = $this->governedRunAwaitingReviewAcknowledgement();
        $review = (new TaskTransparencyGateway($this->root))->task('APP-1')->review;
        self::assertNotSame([], $review->findings, 'Fixture precondition: the owner report must carry findings.');

        $response = $app->handle(new Request('GET', '/task/APP-1'));

        self::assertSame(200, $response->status, 'The decision page must not fail where a decision is owed.');
        $main = $this->main($response->body);
        self::assertStringContainsString('Acknowledge the exact review report', $main);
        self::assertStringContainsString((string) $review->sha256, $main, 'The decision is bound to this exact digest.');
        foreach ($review->findings as $finding) {
            self::assertStringContainsString(htmlspecialchars($finding->message, ENT_QUOTES), $main);
            self::assertStringContainsString('[' . $finding->severity . ']', $main, 'Severity is the owner\'s word, shown as given.');
        }
    }

    /**
     * A decision is owed on this task, so every view of it says so and links to it.
     *
     * The decision controls sit on the task overview, a long way below the header, and
     * the Contract, Work, Evidence and History views have no controls at all. A returning
     * reader on any of them saw "command template" in the header and had to know that the
     * owner models "a human must acknowledge this" as a command a human runs, and that the
     * overview is where the form lives. The link is shown because agent-loop's own
     * projection lists a recordable action, not because the page read a state name.
     */
    public function testEveryTaskViewLinksToTheDecisionWhileOneIsProjected(): void
    {
        $app = $this->governedRunAwaitingReviewAcknowledgement();
        self::assertSame(
            ['acknowledge_review'],
            (new HumanDecisionGateway($this->root))->available('APP-1')->actions,
            'Fixture precondition: agent-loop projects the review acknowledgement as recordable.',
        );

        foreach (['', '/progress', '/contract', '/context', '/work', '/evidence', '/history', '/prompts', '/learning', '/edit'] as $view) {
            $response = $app->handle(new Request('GET', '/task/APP-1' . $view));
            self::assertSame(200, $response->status, '/task/APP-1' . $view);
            self::assertStringContainsString('href="/task/APP-1#decision"', $response->body, $view . ' does not lead to the decision');
        }
    }

    public function testTheDecisionPanelIsWhatTheLinkLandsOn(): void
    {
        $app = $this->governedRunAwaitingReviewAcknowledgement();

        $body = $app->handle(new Request('GET', '/task/APP-1'))->body;

        self::assertSame(1, substr_count($body, 'id="decision"'), 'The link needs exactly one target.');
        self::assertMatchesRegularExpression('~<section[^>]*\bid="decision"[^>]*>\s*<[^>]*>[^<]*(?:<[^>]*>[^<]*)*Acknowledge the exact review report~s', $body, 'The target must be the decision panel.');
    }

    public function testNoDecisionLinkIsShownWhenAgentLoopProjectsNone(): void
    {
        $app = $this->governedRunAwaitingReviewAcknowledgement();
        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => 'APP-2',
            'title' => 'Unplanned task',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'No contract yet',
            'task_brief' => 'Nothing proposed.',
            'validation' => 'composer test',
            'priority' => '2',
            'assignee' => 'dev',
        ]));
        self::assertSame(303, $created->status);
        self::assertSame([], (new HumanDecisionGateway($this->root))->available('APP-2')->actions, 'Fixture precondition.');

        $body = $app->handle(new Request('GET', '/task/APP-2/contract'))->body;

        self::assertStringNotContainsString('#decision', $body);
    }

    private function governedRunAwaitingReviewAcknowledgement(): Application
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => 'APP-1',
            'title' => 'Greeter says hello',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Make the greeter friendlier',
            'task_brief' => 'Change Greeter::hi.',
            'validation' => 'php -l src/Greeter.php',
            'priority' => '2',
            'assignee' => 'dev',
        ]));
        self::assertSame(303, $created->status);

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            taskId: 'APP-1',
            goal: 'Make the greeter friendlier without changing its public API.',
            scope: ['src/Greeter.php'],
            nonGoals: ['no new dependencies'],
            validation: ['php -l src/Greeter.php'],
            plannedBy: 'planner-agent',
            baseCommit: trim($this->git('rev-parse HEAD')),
            acceptanceCriteria: ['hi() returns a greeting containing the word hello'],
        );
        $contracts->approve('APP-1', 'maintainer');

        self::assertSame(0, $this->frontDoor('enter'));
        file_put_contents($this->root . '/src/Greeter.php', "<?php\nfinal class Greeter { public function hi(): string { return 'hello there'; } }\n");
        self::assertSame(1, $this->frontDoor('finish'), 'finish must stop at the review acknowledgement.');

        return $app;
    }

    private function frontDoor(string $command): int
    {
        ob_start();
        try {
            return (new HostFrontDoorApplication($this->root))->run($command, ['APP-1', '--format=json']);
        } finally {
            ob_end_clean();
        }
    }

    private function git(string $arguments): string
    {
        $output = shell_exec('cd ' . escapeshellarg($this->root) . ' && git ' . $arguments . ' 2>&1');

        return is_string($output) ? $output : '';
    }

    /** The page body between `<main>` tags, so layout CSS and script text cannot satisfy an assertion. */
    private function main(string $body): string
    {
        $start = strpos($body, '<main');
        $end = strpos($body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($body, $start, $end - $start);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) && !is_link($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
