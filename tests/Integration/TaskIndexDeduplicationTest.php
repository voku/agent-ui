<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

final class TaskIndexDeduplicationTest extends TestCase
{
    private string $root;
    private string $templates;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-task-index-' . bin2hex(random_bytes(6));
        $this->templates = dirname(__DIR__, 2) . '/templates';

        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }

        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTaskSummaryDoesNotRepeatPersistentWorkspaceFactsOrNavigation(): void
    {
        $app = $this->applicationWithCard();
        $response = $app->handle(new Request('GET', '/task/APP-1'));

        self::assertSame(200, $response->status);

        $body = $response->body;
        $nextAction = (new WorkflowProjectionGateway($this->root))->task('APP-1')->nextAction;

        self::assertSame(1, substr_count($body, TemplateRenderer::escape($nextAction)));
        self::assertSame(1, substr_count($body, 'href="/task/APP-1/progress"'));
        self::assertSame(1, substr_count($body, 'href="/task/APP-1/history"'));
        self::assertSame(1, substr_count($body, 'href="/task/APP-1/edit"'));
        self::assertStringNotContainsString('class="crumbs"', $body);
        self::assertStringNotContainsString('>Current state<', $body);
        self::assertStringContainsString('Find code &amp; impact', $body);
        self::assertStringContainsString('Architecture', $body);
    }

    private function applicationWithCard(): Application
    {
        $app = new Application($this->root, $this->templates);
        $csrf = (new CsrfTokenManager())->token();

        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => $csrf,
            'card_id' => 'APP-1',
            'title' => 'Build login system',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Support secure user login',
            'task_brief' => 'Detailed brief for building login.',
            'validation' => 'composer test',
            'priority' => '2',
            'assignee' => 'developer',
        ]));

        self::assertSame(303, $created->status);

        return $app;
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
