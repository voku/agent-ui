<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Feature\Task\TaskContextComposer;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\Security\CsrfTokenManager;

final class TaskContextOwnerSnapshotReuseTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-context-snapshot-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents(
            $this->root . '/.agent-loop/todo/board.md',
            "# Board Metadata\n\n- **Project prefix:** APP\n",
        );

        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => 'APP-1',
            'title' => 'Snapshot reuse',
            'lane' => 'BACKLOG',
            'status' => 'todo',
        ]));
        self::assertSame(303, $created->status);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testOwnerSnapshotsComposeTheSameContextAsFreshConvenienceReads(): void
    {
        $board = new BoardProjectionGateway($this->root);
        $workflow = new WorkflowProjectionGateway($this->root);
        $decisions = new HumanDecisionGateway($this->root);
        $composer = new TaskContextComposer($board, $workflow, $decisions);

        $card = $board->card('APP-1');
        $workflowSnapshot = $workflow->task('APP-1');
        $humanDecisions = $decisions->available('APP-1');

        self::assertEquals(
            $composer->forCard($card),
            $composer->fromOwnerSnapshots($card, $workflowSnapshot, $humanDecisions),
        );
        self::assertEquals(
            $composer->forTask('APP-1'),
            $composer->forTaskWithWorkflow('APP-1', $workflowSnapshot),
        );
    }

    public function testMismatchedWorkflowSnapshotFailsClosed(): void
    {
        $board = new BoardProjectionGateway($this->root);
        $workflow = new WorkflowProjectionGateway($this->root);
        $decisions = new HumanDecisionGateway($this->root);
        $composer = new TaskContextComposer($board, $workflow, $decisions);

        $card = $board->card('APP-1');
        $snapshot = $workflow->task('APP-1');
        $foreign = new WorkflowSnapshot(
            taskId: 'APP-2',
            runId: $snapshot->runId,
            mode: $snapshot->mode,
            state: $snapshot->state,
            references: $snapshot->references,
            disagreements: $snapshot->disagreements,
            nextAction: $snapshot->nextAction,
            nextActionKind: $snapshot->nextActionKind,
        );

        $this->expectException(InvalidArgumentException::class);
        $composer->fromOwnerSnapshots($card, $foreign, $decisions->available('APP-1'));
    }

    public function testMismatchedHumanDecisionSnapshotFailsClosed(): void
    {
        $board = new BoardProjectionGateway($this->root);
        $workflow = new WorkflowProjectionGateway($this->root);
        $decisions = new HumanDecisionGateway($this->root);
        $composer = new TaskContextComposer($board, $workflow, $decisions);

        $card = $board->card('APP-1');

        $this->expectException(InvalidArgumentException::class);
        $composer->fromOwnerSnapshots(
            $card,
            $workflow->task('APP-1'),
            new WorkflowHumanDecisionProjection('APP-2', [], null),
        );
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
