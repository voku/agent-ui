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
}
