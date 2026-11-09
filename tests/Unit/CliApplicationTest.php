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
        self::assertStringContainsString('agent-ui session start TASK', $output);
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

    public function testSessionUsesTheSharedNodeImplementationWithoutAShell(): void
    {
        $temporaryRoot = sys_get_temp_dir() . '/agent-ui-session-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($temporaryRoot, 0o700));
        $project = $temporaryRoot . '/project$(touch PWNED)';
        self::assertTrue(mkdir($project, 0o700));
        $node = $temporaryRoot . '/node';
        $capture = $temporaryRoot . '/arguments';
        file_put_contents($node, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$AGENT_UI_TEST_CAPTURE\"\nexit 23\n");
        chmod($node, 0o700);
        $environment = getenv();
        $environment['PATH'] = $temporaryRoot . PATH_SEPARATOR . ($environment['PATH'] ?? '');
        $environment['AGENT_UI_TEST_CAPTURE'] = $capture;
        $bin = dirname(__DIR__, 2) . '/bin/agent-ui';

        try {
            $process = proc_open(
                [PHP_BINARY, $bin, 'session', 'start', 'UI-145', '--root=' . $project, '--host=codex', '--port=18090'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $temporaryRoot,
                $environment,
            );
            self::assertIsResource($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            self::assertSame(23, proc_close($process));
            $arguments = file($capture, FILE_IGNORE_NEW_LINES);
            self::assertSame([
                dirname(__DIR__, 2) . '/tools/terminal/local-cockpit.cjs',
                '--root=' . $project,
                '--task=UI-145',
                '--start=codex',
                '--port=18090',
            ], $arguments);
            self::assertFileDoesNotExist($temporaryRoot . '/PWNED');

            // Antigravity (agy) is also supported as an explicit direct host.
            $agyProcess = proc_open(
                [PHP_BINARY, $bin, 'session', 'start', 'UI-145', '--root=' . $project, '--host=agy', '--port=18090'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $agyPipes,
                $temporaryRoot,
                $environment,
            );
            self::assertIsResource($agyProcess);
            foreach ($agyPipes as $pipe) {
                fclose($pipe);
            }
            self::assertSame(23, proc_close($agyProcess));
            self::assertSame([
                dirname(__DIR__, 2) . '/tools/terminal/local-cockpit.cjs',
                '--root=' . $project,
                '--task=UI-145',
                '--start=agy',
                '--port=18090',
            ], file($capture, FILE_IGNORE_NEW_LINES));

            // The default is now a real interactive shell, not a vendor-specific host.
            $shellProcess = proc_open(
                [PHP_BINARY, $bin, 'session', 'start', 'UI-145', '--root=' . $project, '--port=18090'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $shellPipes,
                $temporaryRoot,
                $environment,
            );
            self::assertIsResource($shellProcess);
            foreach ($shellPipes as $pipe) {
                fclose($pipe);
            }
            self::assertSame(23, proc_close($shellProcess));
            self::assertSame([
                dirname(__DIR__, 2) . '/tools/terminal/local-cockpit.cjs',
                '--root=' . $project,
                '--task=UI-145',
                '--start=shell',
                '--port=18090',
            ], file($capture, FILE_IGNORE_NEW_LINES));
            self::assertFileDoesNotExist($temporaryRoot . '/PWNED');
        } finally {
            @unlink($capture);
            @unlink($node);
            @rmdir($project);
            @rmdir($temporaryRoot);
        }
    }

    public function testSessionRejectsUnsupportedHostsBeforeLaunchingNode(): void
    {
        $bin = dirname(__DIR__, 2) . '/bin/agent-ui';
        $process = proc_open(
            [PHP_BINARY, $bin, 'session', 'start', 'UI-145', '--host=sh'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $stdout);
        self::assertIsString($stderr);
        self::assertStringContainsString('invalid session root, host, port or format', $stderr);
    }
}
