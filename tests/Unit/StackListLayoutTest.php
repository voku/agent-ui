<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A `.stack` of paths, commands or ids reads as separate entries, not as one long word.
 *
 * `.stack` separates its children with `margin-top`, and a top margin does nothing to an
 * inline element. Every list of `<code>` paths, validation commands or `<a class="mono">`
 * ids inside one therefore laid out on a single run with no gap, so a Contract scope of
 * `src/Greeter.php` and `tests/GreeterTest.php` read as the one path
 * `src/Greeter.phptests/GreeterTest.php` - on the screen where a human approves that scope,
 * and on the Work, Contract and Knowledge pages, where 37 paths changed outside scope
 * would have run together.
 *
 * This is checked in a real browser when the rule changes (the children's computed
 * `display`); what the test keeps is that the rule that makes them blocks stays in the
 * stylesheet and still covers the element kinds the templates put there.
 */
final class StackListLayoutTest extends TestCase
{
    public function testInlineListEntriesInAStackAreBlockLevel(): void
    {
        $rule = $this->ruleFor('.stack > :is(');

        foreach (['code', 'a', 'span'] as $element) {
            self::assertMatchesRegularExpression('~:is\([^)]*\b' . $element . '\b[^)]*\)~', $rule['selector'], $element . ' entries run together without this.');
        }
        self::assertMatchesRegularExpression('~display:\s*block~', $rule['body']);
    }

    public function testButtonsAndPillsInAStackAreLeftAlone(): void
    {
        $rule = $this->ruleFor('.stack > :is(');

        self::assertStringContainsString(':not(.btn, .pill)', $rule['selector'], 'A row of buttons in a stack must stay a row.');
    }

    public function testALongPathCannotWidenItsContainer(): void
    {
        $rule = $this->ruleFor('.stack > :is(');

        self::assertMatchesRegularExpression('~overflow-wrap:\s*anywhere~', $rule['body']);
        self::assertMatchesRegularExpression('~max-width:\s*100%~', $rule['body']);
    }

    /** @return array{selector: string, body: string} */
    private function ruleFor(string $selectorStart): array
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/layout/app.css');
        $found = preg_match('~(' . preg_quote($selectorStart, '~') . '[^{]*)\{([^}]*)\}~', $css, $match);
        self::assertSame(1, $found, 'No rule starting with `' . $selectorStart . '` in app.css.');

        return ['selector' => $match[1], 'body' => $match[2]];
    }
}
