<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * "What changed since the previous approved state?" is read one entry at a time.
 *
 * The delta between two Contract revisions is a set difference per list, and one criterion
 * reworded is, correctly, one removal and one addition. Printed on a single line as
 * `+ hi() returns a greeting containing hello  + bye() exists  - hi() returns ... the word hello`
 * the reader has to find where each entry ends and pair the reworded one by eye, on the
 * screen where they decide whether to approve it. Each entry is its own line instead.
 */
final class ContractDecisionDeltaPageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-delta-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create the fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEachAddedAndRemovedEntryIsItsOwnLine(): void
    {
        $app = $this->revisedContract();

        $main = $this->main($app->handle(new Request('GET', '/task/APP-1'))->body);

        $found = preg_match('~Acceptance criteria:</span>\s*<div class="stack"[^>]*>(.*?)</div>~s', $main, $block);
        self::assertSame(1, $found, 'The acceptance delta must be a stack of entries.');
        $entries = $block[1];
        self::assertSame(3, substr_count($entries, '<code>'), 'Two additions and one removal are three entries.');
        self::assertStringContainsString('<code>+ hi() returns a greeting containing hello</code>', $entries);
        self::assertStringContainsString('<code>+ bye() exists</code>', $entries);
        self::assertStringContainsString('<code>&minus; hi() returns a greeting containing the word hello</code>', $entries);
    }

    public function testTheScopeAdditionIsStillShownAsAnAddition(): void
    {
        $app = $this->revisedContract();

        $main = $this->main($app->handle(new Request('GET', '/task/APP-1'))->body);

        self::assertStringContainsString('<code>+ tests/GreeterTest.php</code>', $main);
    }

    private function revisedContract(): Application
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
            acceptanceCriteria: ['hi() returns a greeting containing the word hello'],
        );
        $contracts->approve('APP-1', 'maintainer');
        $contracts->revise(
            taskId: 'APP-1',
            goal: 'Make the greeter friendlier without changing its public API.',
            scope: ['src/Greeter.php', 'tests/GreeterTest.php'],
            nonGoals: ['no new dependencies'],
            validation: ['php -l src/Greeter.php'],
            plannedBy: 'planner-agent',
            acceptanceCriteria: ['hi() returns a greeting containing hello', 'bye() exists'],
        );

        return $app;
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
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
