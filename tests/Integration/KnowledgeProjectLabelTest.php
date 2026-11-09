<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

final class KnowledgeProjectLabelTest extends TestCase
{
    private string $root;
    private Application $app;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-knowledge-meta-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        $learning = $this->root . '/.agent-loop/learning';
        mkdir($learning . '/findings/validated', 0o775, true);
        file_put_contents(
            $learning . '/config.json',
            json_encode(['schema_version' => '1.0', 'task_id_pattern' => '^[A-Z]+-[0-9]+$'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/todo/board.md',
            "# Board Metadata\n\n- **Project prefix:** TESTPROJ\n",
        );
        $this->app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testKnowledgeOverviewDisplaysProjectPrefixInMasthead(): void
    {
        $response = $this->app->handle(new Request('GET', '/knowledge'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString(
            '<div class="masthead__meta"><span class="mono">TESTPROJ</span></div>',
            $response->body,
        );
    }

    public function testDreamPageDisplaysProjectPrefixInMasthead(): void
    {
        $response = $this->app->handle(new Request('GET', '/knowledge/dream'));

        self::assertContains($response->status, [200, 503]);
        self::assertStringContainsString(
            '<div class="masthead__meta"><span class="mono">TESTPROJ</span></div>',
            $response->body,
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
