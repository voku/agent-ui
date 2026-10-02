<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;

final class WorkflowProjectionGatewayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-workflow-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/map', 0o775, true)) {
            throw new RuntimeException('Unable to create workflow fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testProjectsSeveralTasksThroughTheOwnerBatchApi(): void
    {
        $gateway = new WorkflowProjectionGateway($this->root);

        $snapshots = $gateway->tasks(['APP-1', 'APP-2']);

        self::assertSame(['APP-1', 'APP-2'], array_keys($snapshots));
        self::assertSame('APP-1', $snapshots['APP-1']->taskId);
        self::assertSame('APP-2', $snapshots['APP-2']->taskId);
        self::assertSame($gateway->task('APP-1')->nextActionKind, $snapshots['APP-1']->nextActionKind);
        self::assertSame($gateway->task('APP-2')->nextActionKind, $snapshots['APP-2']->nextActionKind);
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
