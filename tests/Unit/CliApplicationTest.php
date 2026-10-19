<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CliApplicationTest extends TestCase
{
    public function testBinHelpOutputsUsage(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin/agent-ui';
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' --help');

        self::assertStringContainsString('Usage:', $output);
        self::assertStringContainsString('agent-ui [serve]', $output);
        self::assertStringContainsString('agent-ui status', $output);
        self::assertStringContainsString('--port=PORT', $output);
    }

    public function testBinPassesComposerAutoloaderToRouter(): void
    {
        $root = dirname(__DIR__, 2);
        $temporaryRoot = sys_get_temp_dir() . '/agent-ui-router-autoload-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($temporaryRoot, 0o700));

        $marker = $temporaryRoot . '/autoload.marker';
        $autoload = $temporaryRoot . '/consumer-autoload.php';
        $launcher = $temporaryRoot . '/consumer-agent-ui.php';
        $port = $this->availablePort();

        file_put_contents($autoload, sprintf(
            "<?php\nfile_put_contents(%s, \"loaded\\n\", FILE_APPEND);\nrequire %s;\n",
            var_export($marker, true),
            var_export($root . '/vendor/autoload.php', true),
        ));
        file_put_contents($launcher, sprintf(
            "<?php\n\$_composer_autoload_path = %s;\nrequire %s;\n",
            var_export($autoload, true),
            var_export($root . '/bin/agent-ui', true),
        ));

        $process = proc_open(
            [PHP_BINARY, $launcher, '--host=127.0.0.1', '--port=' . $port],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );

        self::assertIsResource($process);

        try {
            $response = false;
            for ($attempt = 0; $attempt < 50; ++$attempt) {
                $response = @file_get_contents('http://127.0.0.1:' . $port . '/knowledge/dream');
                if (is_string($response)) {
                    break;
                }
                usleep(100_000);
            }

            self::assertIsString($response, 'The launched control plane should answer the Dream route.');
            self::assertStringContainsString('Dream', $response);
            self::assertSame("loaded\nloaded\n", file_get_contents($marker), 'The selected Composer autoloader must run in both launcher and router processes.');
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);

            foreach ([$marker, $autoload, $launcher] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($temporaryRoot);
        }
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (!is_resource($socket)) {
            throw new \RuntimeException(sprintf('Unable to reserve a local test port: %s (%d).', $errorMessage, $errorCode));
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($address)) {
            throw new \RuntimeException('Unable to read the reserved local test port.');
        }

        $separator = strrpos($address, ':');
        if ($separator === false) {
            throw new \RuntimeException('The reserved local socket address has no port separator.');
        }

        return (int) substr($address, $separator + 1);
    }

    public function testBinRejectsNonExistentRootDirectory(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin/agent-ui';
        $command = sprintf(
            '%s %s --root=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($bin),
            escapeshellarg('/non/existent/directory/path/12345'),
        );

        exec($command, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Project root directory does not exist', $output);
    }

    public function testStatusReturnsStructuredUnreachableResult(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin/agent-ui';
        $command = sprintf(
            '%s %s status --root=%s --host=127.0.0.1 --port=1 --format=json 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($bin),
            escapeshellarg(dirname(__DIR__, 2)),
        );

        exec($command, $outputLines, $exitCode);
        $payload = json_decode(implode("\n", $outputLines), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            self::fail('Expected status to return a JSON object.');
        }

        self::assertSame(2, $exitCode);
        self::assertSame('unreachable', $payload['status'] ?? null);
        self::assertSame('http://127.0.0.1:1', $payload['url'] ?? null);
        self::assertIsString($payload['project_id'] ?? null);
    }
}
