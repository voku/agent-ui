<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Http\Response;
use voku\AgentUi\View\ClientScript;

#[CoversClass(ClientScript::class)]
#[CoversClass(Response::class)]
final class ClientScriptTest extends TestCase
{
    public function testTheContentSecurityPolicyPermitsTheScriptTheLayoutActuallyShips(): void
    {
        $expected = "'sha256-" . base64_encode(hash('sha256', ClientScript::code(), true)) . "'";

        self::assertStringContainsString('script-src ' . $expected . ';', Response::contentSecurityPolicy());
    }

    public function testTheScriptSourceIsNamedByHashRatherThanAllowingAnyInlineScript(): void
    {
        $policy = Response::contentSecurityPolicy();

        self::assertStringNotContainsString("script-src 'unsafe-inline'", $policy);
        self::assertStringNotContainsString("script-src 'self'", $policy);
        self::assertStringContainsString("script-src 'sha256-", $policy);
    }

    public function testTheEnhancementScriptRevealsCopyButtonsRatherThanAssumingTheyAreVisible(): void
    {
        self::assertStringContainsString('button.hidden = false', ClientScript::code());
    }
}
