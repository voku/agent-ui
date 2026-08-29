<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;

/**
 * A repository scaffolded by `agent-loop init scaffold` keeps its board below
 * the state root. Spelling `<project root>/todo` here instead of asking
 * agent-loop's layout owner made every page of the control plane answer 500.
 */
final class BoardProjectionGatewayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-board-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
    }

    protected function tearDown(): void
    {
        foreach (['/.agent-loop/todo/cards/TEST-1.md', '/.agent-loop/todo/board.md'] as $file) {
            if (is_file($this->root . $file)) {
                unlink($this->root . $file);
            }
        }
        foreach (['/.agent-loop/todo/cards', '/.agent-loop/todo', '/.agent-loop', ''] as $directory) {
            if (is_dir($this->root . $directory)) {
                rmdir($this->root . $directory);
            }
        }
    }

    public function testReadsTheBoardAgentLoopOwnsBelowTheStateRoot(): void
    {
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** TEST\n");
        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/TEST-1.md',
            "# TEST-1 — Board lives below the state root\n\n- **Lane:** BACKLOG\n- **Status:** todo\n",
        );

        $board = (new BoardProjectionGateway($this->root))->board();

        self::assertSame('TEST', $board->projectPrefix);
        self::assertCount(1, $board->cards);
        self::assertSame('TEST-1', $board->cards[0]->id);
    }
}
