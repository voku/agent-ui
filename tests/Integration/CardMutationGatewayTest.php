<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Integration\AgentKanban\CardMutationGateway;

final class CardMutationGatewayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-mutation-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** TEST\n");
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

    public function testSuggestsNextIdWhenEmpty(): void
    {
        $gateway = new CardMutationGateway($this->root);
        self::assertSame('TEST-1', $gateway->suggestNextId());
    }

    public function testCreatesCardAndUpdatesFields(): void
    {
        $gateway = new CardMutationGateway($this->root);

        $created = $gateway->create(
            cardId: 'TEST-1',
            title: 'Initial feature implementation',
            lane: 'BACKLOG',
            status: 'todo',
            summary: 'Implement the first feature',
            taskBrief: 'Detailed task description with requirements.',
            nextAction: 'vendor/bin/agent-loop enter TEST-1',
            validation: 'composer test',
            priority: 2,
            assignee: 'alice',
        );

        self::assertSame('TEST-1', $created->id);
        self::assertSame('Initial feature implementation', $created->title);
        self::assertSame('BACKLOG', $created->lane);
        self::assertSame('todo', $created->status);
        self::assertSame(2, $created->priority);
        self::assertSame('alice', $created->assignee);
        self::assertSame('Detailed task description with requirements.', $created->taskBrief);
        self::assertNotEmpty($created->revision);

        // Next suggested ID should now be TEST-2
        self::assertSame('TEST-2', $gateway->suggestNextId());

        // File should exist on disk
        self::assertFileExists($this->root . '/.agent-loop/todo/cards/TEST-1.md');

        // Allowed transitions from BACKLOG
        $allowed = $gateway->allowedTransitions('TEST-1');
        self::assertContains('READY', $allowed);

        // Move to READY
        $moved = $gateway->move('TEST-1', 'READY', actor: 'alice');
        self::assertSame('READY', $moved->lane);

        // Update card
        $updated = $gateway->update(
            taskId: 'TEST-1',
            title: 'Updated feature title',
            status: 'in_progress',
            priority: 1,
            assignee: 'bob',
        );
        self::assertSame('Updated feature title', $updated->title);
        self::assertSame('in_progress', $updated->status);
        self::assertSame(1, $updated->priority);
        self::assertSame('bob', $updated->assignee);

        // Claim and Release
        $claimed = $gateway->claim('TEST-1', actor: 'bob');
        self::assertSame('bob', $claimed->claimActor);

        $released = $gateway->release('TEST-1', actor: 'bob');
        self::assertNull($released->claimActor);
    }
}
