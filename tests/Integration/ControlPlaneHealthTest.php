<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

final class ControlPlaneHealthTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-health-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o775, true)) {
            throw new RuntimeException('Unable to create project root fixture.');
        }
    }

    protected function tearDown(): void
    {
        rmdir($this->root);
    }

    public function testHealthIdentifiesExactProjectWithoutMutatingOrExposingItsPath(): void
    {
        $before = scandir($this->root);
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');

        $response = $app->handle(new Request('GET', '/__agent-ui/health'));

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $payload = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('agent-ui', $payload['service'] ?? null);
        self::assertSame(1, $payload['schema'] ?? null);
        self::assertSame('ready', $payload['status'] ?? null);
        self::assertSame('sha256:' . hash('sha256', (string) realpath($this->root)), $payload['project_id'] ?? null);
        self::assertStringNotContainsString($this->root, $response->body);
        self::assertSame($before, scandir($this->root));
    }
}
