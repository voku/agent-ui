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
        $this->removeDirectory($this->root);
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

    public function testProjectsMultipleBoardsFromConfiguration(): void
    {
        mkdir($this->root . '/.agent-loop/todo/jira', 0o775, true);
        mkdir($this->root . '/.agent-loop/todo/followups', 0o775, true);

        file_put_contents(
            $this->root . '/.agent-loop/todo/kanban.config.json',
            json_encode([
                'defaultBoard' => 'jira',
                'boards' => [
                    [
                        'id' => 'jira',
                        'title' => 'Jira Tasks',
                        'projectPrefix' => 'JIRA',
                        'cardDirectory' => 'todo/jira',
                    ],
                    [
                        'id' => 'followups',
                        'title' => 'Followups',
                        'projectPrefix' => 'FOL',
                        'cardDirectory' => 'todo/followups',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        file_put_contents(
            $this->root . '/.agent-loop/todo/jira/JIRA-1.md',
            "# JIRA-1 — First\n\n- **Lane:** BACKLOG\n- **Status:** todo\n",
        );
        file_put_contents(
            $this->root . '/.agent-loop/todo/followups/FOL-1.md',
            "# FOL-1 — Second\n\n- **Lane:** READY\n- **Status:** todo\n- **Task brief:** Brief\n",
        );

        $gateway = new BoardProjectionGateway($this->root);
        $defaultBoard = $gateway->board();

        self::assertSame('JIRA', $defaultBoard->projectPrefix);
        self::assertSame('jira', $defaultBoard->id);
        self::assertCount(2, $defaultBoard->boards);
        self::assertCount(1, $defaultBoard->cards);

        $followupsBoard = $gateway->board('followups');
        self::assertSame('FOL', $followupsBoard->projectPrefix);
        self::assertSame('followups', $followupsBoard->id);
        self::assertCount(1, $followupsBoard->cards);
        self::assertSame('FOL-1', $followupsBoard->cards[0]->id);

        $card = $gateway->card('FOL-1');
        self::assertSame('FOL-1', $card->id);
    }
}
