<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * A card list is projected against one agent-loop read snapshot. The batch is a
 * cost optimisation only: every snapshot must equal what a single task() call
 * returns, and nothing may outlive the batch.
 */
final class WorkflowProjectionBatchTest extends TestCase
{
    private string $root;
    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-batch-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
        $this->app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $this->createCard('APP-1');
        $this->createCard('APP-2');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testBatchEqualsIndividualProjectionsInInputOrder(): void
    {
        $gateway = new WorkflowProjectionGateway($this->root);

        $batch = $gateway->tasks(['APP-2', 'APP-1']);

        self::assertSame(['APP-2', 'APP-1'], array_keys($batch));
        self::assertEquals($gateway->task('APP-2'), $batch['APP-2']);
        self::assertEquals($gateway->task('APP-1'), $batch['APP-1']);
    }

    public function testInvalidTaskIdIsUnprojectableWithoutHidingOtherCards(): void
    {
        $gateway = new WorkflowProjectionGateway($this->root);

        $batch = $gateway->tasks(['APP-1', '../escape', 'APP-2']);

        self::assertSame(['APP-1', '../escape', 'APP-2'], array_keys($batch));
        self::assertNull($batch['../escape']);
        self::assertEquals($gateway->task('APP-1'), $batch['APP-1']);
        self::assertEquals($gateway->task('APP-2'), $batch['APP-2']);

        $this->expectException(\Throwable::class);
        $gateway->task('../escape');
    }

    public function testBatchHoldsNoStateAcrossCalls(): void
    {
        $gateway = new WorkflowProjectionGateway($this->root);

        self::assertArrayNotHasKey('APP-3', $gateway->tasks(['APP-1']));
        $this->createCard('APP-3');
        $batch = $gateway->tasks(['APP-1', 'APP-3']);

        self::assertEquals($gateway->task('APP-3'), $batch['APP-3']);
    }

    public function testEmptyBatchIsEmpty(): void
    {
        self::assertSame([], (new WorkflowProjectionGateway($this->root))->tasks([]));
    }

    public function testHomeAndBoardPagesRenderEveryCardThroughTheBatch(): void
    {
        foreach (['/', '/board'] as $path) {
            $response = $this->app->handle(new Request('GET', $path));
            self::assertSame(200, $response->status, $path);
        }
        $home = $this->app->handle(new Request('GET', '/'));
        self::assertStringContainsString('APP-1', $home->body);
        self::assertStringContainsString('APP-2', $home->body);
    }

    private function createCard(string $id): void
    {
        $response = $this->app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => $id,
            'title' => 'Card ' . $id,
            'lane' => 'BACKLOG',
            'status' => 'todo',
        ]));
        self::assertSame(303, $response->status);
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
