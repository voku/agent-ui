<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Feature\Home\HomeAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Security\CsrfTokenManager;

final class CurrentWorkBoundingTest extends TestCase
{
    private string $root;
    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-bounding-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents(
            $this->root . '/.agent-loop/todo/board.md',
            "# Board Metadata\n\n- **Project prefix:** BOUND\n",
        );
        $this->app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCurrentWorkIsBoundedToMaximumCurrentWork(): void
    {
        $csrf = (new CsrfTokenManager())->token();

        // Create 15 cards (more than MAXIMUM_CURRENT_WORK = 10)
        for ($i = 1; $i <= 15; $i++) {
            $id = 'BOUND-' . $i;
            $this->app->handle(new Request('POST', '/board/new', body: [
                '_csrf' => $csrf,
                'card_id' => $id,
                'title' => 'Card ' . $id,
                'lane' => 'BACKLOG',
                'status' => 'todo',
                'summary' => 'Fixture card ' . $id,
                'task_brief' => 'Brief for ' . $id,
                'validation' => 'composer test',
            ]));
        }

        $response = $this->app->handle(new Request('GET', '/'));
        self::assertSame(200, $response->status);

        // Check that the overflow note is rendered
        self::assertStringContainsString(
            'Showing ' . HomeAction::MAXIMUM_CURRENT_WORK . ' of 15 active tasks.',
            $response->body,
        );
        self::assertStringContainsString('<a href="/board">View all tasks on the Board →</a>', $response->body);

        // The first 10 cards are linked in Current work
        for ($i = 1; $i <= HomeAction::MAXIMUM_CURRENT_WORK; $i++) {
            self::assertStringContainsString('href="/task/BOUND-' . $i . '"', $response->body);
        }
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
