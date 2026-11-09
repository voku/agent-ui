<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Runtime\LocalTerminal;

#[CoversClass(LocalTerminal::class)]
#[CoversClass(Response::class)]
final class LocalTerminalTest extends TestCase
{
    public function testOnlyAValidatedLoopbackOriginIsAddedToTheFramePolicy(): void
    {
        $previous = getenv('AGENT_UI_TERMINAL_ORIGIN');

        try {
            foreach ([
                'http://127.0.0.1:0',
                'http://127.0.0.1:80',
                'http://127.0.0.1:65536',
                'http://localhost:8090',
                'https://127.0.0.1:8090',
                'http://127.0.0.1:8090/path',
                'http://127.0.0.1:8090; frame-src *',
                'http://127.0.0.1:8090@attacker.example',
            ] as $invalid) {
                putenv('AGENT_UI_TERMINAL_ORIGIN=' . $invalid);
                self::assertNull(LocalTerminal::origin());
                self::assertStringNotContainsString('frame-src ', Response::contentSecurityPolicy());
            }

            putenv('AGENT_UI_TERMINAL_ORIGIN=http://127.0.0.1:8091');
            self::assertSame('http://127.0.0.1:8091', LocalTerminal::origin());
            self::assertStringContainsString("frame-src 'self' http://127.0.0.1:8091", Response::contentSecurityPolicy());
            self::assertStringContainsString("frame-ancestors 'none'", Response::contentSecurityPolicy());
        } finally {
            putenv($previous === false ? 'AGENT_UI_TERMINAL_ORIGIN' : 'AGENT_UI_TERMINAL_ORIGIN=' . $previous);
        }
    }
}
