<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ControlPlaneHostGuardTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/agent-ui-host-guard-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/.agent-loop/todo/cards', 0775, true);
        file_put_contents($this->projectRoot . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testRejectsUntrustedHostAndCrossOriginWhileServingLoopback(): void
    {
        $port = $this->allocatePort();
        $log = $this->projectRoot . '/server.log';
        $process = $this->startServer($port, $log);

        try {
            $this->waitForServer($port);
            $hostAttack = $this->request($port, 'attacker.example:' . $port, 'http://attacker.example:' . $port);
            self::assertMatchesRegularExpression('/^HTTP\/1\.[01] 421 /', $hostAttack);
            self::assertStringContainsString('Unexpected Host header', $hostAttack);

            $originAttack = $this->request($port, 'localhost:' . $port, 'http://attacker.example:' . $port);
            self::assertMatchesRegularExpression('/^HTTP\/1\.[01] 403 /', $originAttack);

            $differentPortOrigin = $this->request($port, 'localhost:' . $port, 'http://localhost:9999', 'POST');
            self::assertSame(403, $this->statusCode($differentPortOrigin));

            $differentSchemeOrigin = $this->request($port, 'localhost:' . $port, 'https://localhost:' . $port, 'POST');
            self::assertSame(403, $this->statusCode($differentSchemeOrigin));

            $invalidOrigin = $this->request($port, 'localhost:' . $port, 'http://localhost:' . $port . '/path', 'POST');
            self::assertSame(403, $this->statusCode($invalidOrigin));

            $opaqueOrigin = $this->request($port, 'localhost:' . $port, 'null', 'POST');
            self::assertSame(403, $this->statusCode($opaqueOrigin));

            $emptyOrigin = $this->request($port, 'localhost:' . $port, '', 'POST');
            self::assertSame(403, $this->statusCode($emptyOrigin));

            $sameOriginPost = $this->request($port, 'localhost:' . $port, 'http://localhost:' . $port, 'POST');
            self::assertNotSame(403, $this->statusCode($sameOriginPost));

            $missingOriginPost = $this->request($port, 'localhost:' . $port, null, 'POST');
            self::assertNotSame(403, $this->statusCode($missingOriginPost));

            $loopback = $this->request($port, 'localhost:' . $port, null);
            self::assertMatchesRegularExpression('/^HTTP\/1\.[01] 200 /', $loopback);
        } finally {
            proc_terminate($process);
            proc_close($process);
            if (is_file($log)) {
                unlink($log);
            }
        }
    }

    public function testUsesTheServerProvidedHttpsSchemeForOriginComparison(): void
    {
        $port = $this->allocatePort();
        $log = $this->projectRoot . '/https-server.log';
        $process = $this->startServer($port, $log, true);

        try {
            $this->waitForServer($port);
            $host = 'localhost:' . $port;
            $sameOrigin = $this->request($port, $host, 'https://' . $host, 'POST');
            self::assertNotSame(403, $this->statusCode($sameOrigin));

            $differentScheme = $this->request($port, $host, 'http://' . $host, 'POST');
            self::assertSame(403, $this->statusCode($differentScheme));
        } finally {
            proc_terminate($process);
            proc_close($process);
            if (is_file($log)) {
                unlink($log);
            }
        }
    }

    private function allocatePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage ?? 'Unable to allocate a loopback test port.');
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    /** @return resource */
    private function startServer(int $port, string $log, bool $https = false): mixed
    {
        $environment = getenv();
        $environment['AGENT_UI_PROJECT_ROOT'] = $this->projectRoot;
        $environment['AGENT_UI_ALLOWED_HOSTS'] = '';
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__, 2) . '/public'];
        if ($https) {
            $router = $this->projectRoot . '/https-router.php';
            file_put_contents(
                $router,
                "<?php\n\$_SERVER['HTTPS'] = 'on';\nrequire " . var_export(dirname(__DIR__, 2) . '/public/index.php', true) . ";\n",
            );
            $command[] = $router;
        }

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return $process;
    }

    private function waitForServer(int $port): void
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }

        self::fail('The PHP development server did not start.');
    }

    private function request(int $port, string $host, ?string $origin, string $method = 'GET'): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 2.0);
        if (!is_resource($socket)) {
            throw new RuntimeException('Could not connect to the PHP development server: ' . $errorMessage);
        }
        $request = "{$method} / HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n";
        if ($method === 'POST') {
            $request .= "Content-Length: 0\r\n";
        }
        if ($origin !== null) {
            $request .= 'Origin: ' . $origin . "\r\n";
        }
        fwrite($socket, $request . "\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        return is_string($response) ? $response : '';
    }

    private function statusCode(string $response): int
    {
        if (!preg_match('/^HTTP\/1\.[01] (\d{3}) /', $response, $matches)) {
            throw new RuntimeException('The HTTP response did not contain a valid status line.');
        }

        return (int) $matches[1];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
        rmdir($directory);
    }
}
