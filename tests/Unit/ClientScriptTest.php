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

        self::assertStringContainsString('script-src ' . $expected, Response::contentSecurityPolicy());
    }

    public function testTheGraphLibraryIsPermittedByHashButNotShippedOnEveryPage(): void
    {
        $library = ClientScript::graphLibrary();
        self::assertNotSame('', $library);
        self::assertStringNotContainsString($library, ClientScript::code());
        self::assertStringContainsString(
            "'sha256-" . base64_encode(hash('sha256', $library, true)) . "'",
            Response::contentSecurityPolicy(),
        );
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

    /**
     * The `/` hint ships hidden and this script is what shows it, so the page
     * cannot advertise a key that nothing is listening for.
     */
    public function testTheSearchShortcutHintIsRevealedByTheScriptThatOperatesIt(): void
    {
        self::assertStringContainsString("document.getElementById('global-search')", ClientScript::code());
        self::assertStringContainsString('hint.hidden = false', ClientScript::code());
    }

    /**
     * A shortcut that fires while someone is typing is worse than none.
     *
     * Read from the source because the behaviour was verified in a real browser
     * and this keeps the guards from being deleted unnoticed: modified keys and
     * form fields (including contenteditable) are left alone.
     */
    public function testTheSearchShortcutLeavesTypingAndModifiedKeysAlone(): void
    {
        $code = ClientScript::code();

        self::assertStringContainsString("event.key !== '/'", $code);
        self::assertStringContainsString('event.ctrlKey || event.metaKey || event.altKey', $code);
        foreach (["'input'", "'textarea'", "'select'", 'isContentEditable'] as $guard) {
            self::assertStringContainsString($guard, $code);
        }
        self::assertStringContainsString("event.key === 'Escape'", $code);
    }

    public function testTheExplorerControlsAreRevealedByTheScriptThatOperatesThem(): void
    {
        // The graph toolbar can only do anything while this script runs, so it
        // ships hidden and the script is what puts it on screen.
        self::assertStringContainsString('toolbar.hidden = false', ClientScript::code());
    }

    public function testTheWorkflowGraphControlsAreRevealedByTheScriptThatOperatesThem(): void
    {
        self::assertStringContainsString('tools.hidden = false', ClientScript::code());
        self::assertStringContainsString('window.cytoscape', ClientScript::code());
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
