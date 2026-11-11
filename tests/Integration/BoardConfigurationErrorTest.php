<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

/**
 * A project without a resolvable agent-kanban configuration is a setup state,
 * not a crash: the board owner's typed message is shown instead of a 500.
 */
final class BoardConfigurationErrorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-board-config-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testMissingBoardConfigurationRendersTheOwnerMessageInsteadOfAServerError(): void
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');

        $response = $app->handle(new Request('GET', '/task/UI-1/progress'));

        self::assertSame(503, $response->status);
        self::assertStringContainsString('Board not configured', $response->body);
        self::assertStringContainsString('Could not determine the project prefix', $response->body);
        self::assertStringContainsString('Reported by agent-kanban', $response->body);
        self::assertStringContainsString('href="/setup"', $response->body);
        self::assertStringNotContainsString('Something went wrong', $response->body);
    }
}
