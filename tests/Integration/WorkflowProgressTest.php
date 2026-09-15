<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

final class WorkflowProgressTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->root = sys_get_temp_dir() . '/agent_ui_workflow_progress_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
        $this->writeCard('UI-36', 'Workflow progress fixture');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testProgressShowsAndRecordsOnlyTheOwnerProjectedContractApproval(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'UI-36',
            'Show governed workflow progress without moving authority into agent-ui.',
            ['src/Feature/Task'],
            ['no workflow percentage'],
            ['composer ci'],
            'planner',
            ['Contract approval is recordable from the workflow view.'],
        );

        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $before = $app->handle(new Request('GET', '/task/UI-36/progress'));

        self::assertSame(200, $before->status);
        self::assertStringContainsString('Human decision required', $before->body);
        self::assertStringContainsString('Approve revision 1', $before->body);
        self::assertStringContainsString('Show governed workflow progress without moving authority into agent-ui.', $before->body);
        self::assertStringContainsString('src/Feature/Task', $before->body);
        self::assertStringContainsString('composer ci', $before->body);
        self::assertStringContainsString('no workflow percentage', $before->body);
        self::assertStringContainsString('Contract approval is recordable from the workflow view.', $before->body);

        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);

        $recorded = $app->handle(new Request(
            'POST',
            '/task/UI-36/approve',
            body: [
                '_csrf' => $csrf,
                'actor' => 'human-reviewer',
                'return_to' => 'progress',
            ],
        ));

        self::assertSame(303, $recorded->status);
        self::assertSame('/task/UI-36/progress', $recorded->headers['Location'] ?? null);
        self::assertSame(TaskContract::APPROVED, $contracts->find('UI-36')?->status);
        self::assertSame('human-reviewer', $contracts->find('UI-36')?->approvedBy);

        $after = $app->handle(new Request('GET', '/task/UI-36/progress'));
        self::assertStringNotContainsString('Approve Contract</button>', $after->body);
    }

    public function testUnknownDecisionReturnTargetFailsBeforeRecordingApproval(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-36', 'Bounded redirect.', ['src'], [], ['composer ci'], 'planner');

        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $app->handle(new Request('GET', '/task/UI-36/progress'));
        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);

        $response = $app->handle(new Request(
            'POST',
            '/task/UI-36/approve',
            body: [
                '_csrf' => $csrf,
                'actor' => 'human-reviewer',
                'return_to' => 'https://example.invalid/',
            ],
        ));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Unsupported human-decision return target.', $response->body);
        self::assertSame(TaskContract::CANDIDATE, $contracts->find('UI-36')?->status);
        self::assertNull($contracts->find('UI-36')?->approvedBy);
    }

    private function writeCard(string $id, string $title): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/' . $id . '.md',
            '# ' . $id . ': ' . $title . "\n\n"
            . "- **Lane:** BACKLOG\n"
            . "- **Status:** todo\n\n"
            . "## Summary\n\n" . $title . "\n",
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
