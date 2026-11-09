<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

final class HeadRequestTest extends TestCase
{
    private string $root;
    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-head-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
        $this->app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testHeadRequestReturnsHeadersWithEmptyBody(): void
    {
        foreach (['/', '/board', '/setup', '/knowledge', '/map', '/search'] as $path) {
            $response = $this->app->handle(new Request('HEAD', $path));

            self::assertSame(200, $response->status, $path . ' must return 200 on HEAD');
            self::assertSame('', $response->body, $path . ' must return empty body on HEAD');
            self::assertArrayHasKey('Content-Type', $response->headers);
        }
    }

    public function testHeadRequestOnMissingRouteReturns404WithEmptyBody(): void
    {
        $response = $this->app->handle(new Request('HEAD', '/nonexistent-route'));

        self::assertSame(404, $response->status);
        self::assertSame('', $response->body);
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
