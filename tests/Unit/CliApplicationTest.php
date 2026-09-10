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
