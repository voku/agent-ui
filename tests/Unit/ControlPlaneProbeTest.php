<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Runtime\ControlPlaneIdentity;
use voku\AgentUi\Runtime\ControlPlaneProbe;

final class ControlPlaneProbeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-probe-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o775, true)) {
            throw new RuntimeException('Unable to create project root fixture.');
        }
    }

    protected function tearDown(): void
    {
        rmdir($this->root);
    }

    public function testCorrectServiceAndProjectIsReadyAndUsesCustomEndpoint(): void
    {
        $identity = ControlPlaneIdentity::fromProjectRoot($this->root);
        $seenHost = null;
        $seenPort = null;
        $probe = new ControlPlaneProbe(static function (string $host, int $port) use (&$seenHost, &$seenPort, $identity): array {
            $seenHost = $host;
            $seenPort = $port;

            return [
                'http_status' => 200,
                'body' => json_encode($identity->payload(), JSON_THROW_ON_ERROR),
                'error' => null,
            ];
        });

        $result = $probe->probe('localhost', 9123, $identity);

        self::assertSame('ready', $result['status']);
        self::assertSame('http://localhost:9123', $result['url']);
        self::assertSame('localhost', $seenHost);
        self::assertSame(9123, $seenPort);
    }

    public function testNoListenerIsUnreachable(): void
    {
        $identity = ControlPlaneIdentity::fromProjectRoot($this->root);
        $probe = new ControlPlaneProbe(static fn(string $host, int $port): array => [
            'http_status' => null,
            'body' => null,
            'error' => 'Connection refused',
        ]);

        $result = $probe->probe('127.0.0.1', 8088, $identity);

        self::assertSame('unreachable', $result['status']);
        self::assertSame('Connection refused', $result['detail']);
    }

    public function testUnrelatedHttpServiceIsWrongService(): void
    {
        $identity = ControlPlaneIdentity::fromProjectRoot($this->root);
        $probe = new ControlPlaneProbe(static fn(string $host, int $port): array => [
            'http_status' => 404,
            'body' => '<html>not agent-ui</html>',
            'error' => null,
        ]);

        self::assertSame('wrong_service', $probe->probe('127.0.0.1', 8088, $identity)['status']);
    }

    public function testAgentUiForAnotherProjectIsWrongProject(): void
    {
        $identity = ControlPlaneIdentity::fromProjectRoot($this->root);
        $otherPayload = $identity->payload();
        $otherPayload['project_id'] = 'sha256:' . str_repeat('0', 64);
        $probe = new ControlPlaneProbe(static fn(string $host, int $port): array => [
            'http_status' => 200,
            'body' => json_encode($otherPayload, JSON_THROW_ON_ERROR),
            'error' => null,
        ]);

        self::assertSame('wrong_project', $probe->probe('127.0.0.1', 8088, $identity)['status']);
    }

    public function testMalformedOrUnsupportedHealthResponseIsInvalid(): void
    {
        $identity = ControlPlaneIdentity::fromProjectRoot($this->root);
        $invalidJson = new ControlPlaneProbe(static fn(string $host, int $port): array => [
            'http_status' => 200,
            'body' => '{nope',
            'error' => null,
        ]);
        $wrongSchema = new ControlPlaneProbe(static fn(string $host, int $port): array => [
            'http_status' => 200,
            'body' => json_encode([
                'service' => 'agent-ui',
                'schema' => 2,
                'status' => 'ready',
                'project_id' => $identity->projectId,
            ], JSON_THROW_ON_ERROR),
            'error' => null,
        ]);

        self::assertSame('invalid_response', $invalidJson->probe('127.0.0.1', 8088, $identity)['status']);
        self::assertSame('invalid_response', $wrongSchema->probe('127.0.0.1', 8088, $identity)['status']);
    }
}
