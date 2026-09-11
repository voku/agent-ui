<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Runtime\ControlPlaneIdentity;

final class ControlPlaneIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-identity-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o775, true)) {
            throw new RuntimeException('Unable to create project root fixture.');
        }
    }

    protected function tearDown(): void
    {
        rmdir($this->root);
    }

    public function testIdentityIsStableAndDoesNotExposeTheProjectPath(): void
    {
        $identity = ControlPlaneIdentity::fromProjectRoot($this->root);
        $expected = 'sha256:' . hash('sha256', (string) realpath($this->root));

        self::assertSame($expected, $identity->projectId);
        self::assertSame([
            'service' => 'agent-ui',
            'schema' => 1,
            'status' => 'ready',
            'project_id' => $expected,
        ], $identity->payload());
        self::assertStringNotContainsString($this->root, json_encode($identity->payload(), JSON_THROW_ON_ERROR));
    }
}
