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
        foreach ([
            '/relocated-board/todo/cards/MOVE-1.md',
            '/relocated-board/todo/board.md',
            '/.agent-loop/init.json',
            '/.agent-loop/todo/cards/TEST-1.md',
            '/.agent-loop/todo/board.md',
        ] as $file) {
            if (is_file($this->root . $file)) {
                unlink($this->root . $file);
            }
        }
        foreach ([
            '/relocated-board/todo/cards',
            '/relocated-board/todo',
            '/relocated-board',
            '/.agent-loop/todo/cards',
            '/.agent-loop/todo',
            '/.agent-loop',
            '',
        ] as $directory) {
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

    public function testReevaluatesTheOwnerBoardRootAfterItIsRelocated(): void
    {
        $gateway = new BoardProjectionGateway($this->root);

        if (!mkdir($this->root . '/relocated-board/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create relocated board fixture root.');
        }
        file_put_contents($this->root . '/relocated-board/todo/board.md', "# Board Metadata\n\n- **Project prefix:** MOVE\n");
        file_put_contents(
            $this->root . '/relocated-board/todo/cards/MOVE-1.md',
            "# MOVE-1 — Board root moved\n\n- **Lane:** BACKLOG\n- **Status:** todo\n",
        );
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'paths' => ['board_root' => 'relocated-board']], JSON_THROW_ON_ERROR),
        );

        $board = $gateway->board();

        self::assertSame('MOVE', $board->projectPrefix);
        self::assertCount(1, $board->cards);
        self::assertSame('MOVE-1', $board->cards[0]->id);
    }
}
