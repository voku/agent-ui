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

    public function testTheExplorerControlsAreRevealedByTheScriptThatOperatesThem(): void
    {
        // The graph toolbar can only do anything while this script runs, so it
        // ships hidden and the script is what puts it on screen.
        self::assertStringContainsString('toolbar.hidden = false', ClientScript::code());
    }

    public function testHiddenChromeStaysHiddenWhateverDisplayAComponentDeclares(): void
    {
        // Browser dogfood caught the graph toolbar rendering for a reader with
        // JavaScript off: `.graph-explorer__bar { display: flex }` is an author
        // rule, and it outranks the user-agent rule behind the hidden
        // attribute. One base rule keeps every progressively-enhanced control
        // honest instead of one guard per component.
        $stylesheet = file_get_contents(dirname(__DIR__, 2) . '/templates/layout/app.css');

        self::assertIsString($stylesheet);
        self::assertMatchesRegularExpression(
            '/\[hidden\]\s*\{\s*display:\s*none\s*!important;?\s*\}/',
            $stylesheet,
        );
    }
}
