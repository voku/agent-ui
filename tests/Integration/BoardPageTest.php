<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\AttentionRequest;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

/**
 * The Board shows agent-kanban's card facts and agent-loop's workflow facts
 * side by side, and filters only by vocabulary the owners actually emitted.
 */
final class BoardPageTest extends TestCase
{
    private string $root;
    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-board-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
        $this->app = new Application($this->root, dirname(__DIR__, 2) . '/templates');

        $created = $this->app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => 'APP-1',
            'title' => 'Build login system',
            'lane' => 'BACKLOG',
            'status' => 'todo',
        ]));
        self::assertSame(303, $created->status);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCardShowsBoardAndWorkflowOwnersSeparately(): void
    {
        $response = $this->app->handle(new Request('GET', '/board'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<dt>Board</dt>', $response->body);
        self::assertStringContainsString('<dt>Workflow</dt>', $response->body);
        self::assertStringContainsString('<dt>Next</dt>', $response->body);
        self::assertStringNotContainsString('agent-loop has no projection', $response->body);
        self::assertStringContainsString('waiting on a human decision', $response->body);
        self::assertStringContainsString('board and workflow disagree', $response->body);
    }

    public function testStatusFilterOffersOnlyStatusesTheBoardHolds(): void
    {
        $response = $this->app->handle(new Request('GET', '/board'));

        self::assertStringContainsString('<option value="todo">todo</option>', $response->body);
        self::assertStringNotContainsString('<option value="open"', $response->body);
        self::assertStringNotContainsString('<option value="done"', $response->body);

        $filtered = $this->app->handle(new Request('GET', '/board', query: ['status' => 'todo']));
        self::assertStringContainsString('Build login system', $filtered->body);
    }

    public function testWorkflowFilterUsesAgentLoopNextActionKind(): void
    {
        $decisions = $this->app->handle(new Request('GET', '/board', query: ['workflow' => 'decision_required']));
        $disagreements = $this->app->handle(new Request('GET', '/board', query: ['workflow' => 'disagreement']));

        self::assertStringNotContainsString('Build login system', $decisions->body);
        self::assertStringContainsString('No cards match the active filters.', $decisions->body);
        self::assertStringNotContainsString('Build login system', $disagreements->body);
    }

    public function testCorruptOwnerProjectionIsReportedAsIncompleteRatherThanEmpty(): void
    {
        $contractPath = (new TaskContractStore($this->root))->path('APP-1');
        self::assertTrue(is_dir(dirname($contractPath)) || mkdir(dirname($contractPath), 0o775, true));
        self::assertNotFalse(file_put_contents($contractPath, '{broken JSON'));

        $response = $this->app->handle(new Request('GET', '/board'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Decision visibility incomplete.', $response->body);
        self::assertStringContainsString('Affected cards:', $response->body);
        self::assertStringContainsString('confirmed waiting; Attention visibility incomplete', $response->body);
        self::assertStringNotContainsString('id="board-decision-queue"', $response->body);
    }

    public function testKnownPendingAttentionRendersEvenWithoutWorkflowSnapshot(): void
    {
        $board = (new BoardProjectionGateway($this->root))->board();
        $attention = new AttentionRequest(
            'attention-1',
            'APP-1',
            'run-1',
            AttentionKind::CLARIFICATION_REQUIRED,
            'Need explicit user decision.',
            'investigate',
            '2026-11-11T12:00:00Z',
        );
        $html = (new TemplateRenderer(dirname(__DIR__, 2) . '/templates'))->render('board/index', [
            'board' => $board,
            'cards' => $board->cards,
            'workflow' => ['APP-1' => null],
            'statuses' => ['todo'],
            'kind_counts' => [],
            'human_decision_count' => 1,
            'pending_attention' => ['APP-1' => $attention],
            'attention_unavailable_ids' => [],
            'disagreement_count' => 0,
            'filter_query' => '',
            'filter_status' => '',
            'filter_priority' => '',
            'filter_workflow' => '',
            'csrf_token' => (new CsrfTokenManager())->token(),
        ]);

        self::assertStringContainsString('id="board-decision-queue"', $html);
        self::assertStringContainsString('href="/task/APP-1#decision"', $html);
        self::assertStringContainsString('Need explicit user decision.', $html);
        self::assertStringContainsString('agent-loop workflow projection: <strong>unavailable</strong>', $html);
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
