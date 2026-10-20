<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The text colour tokens keep WCAG AA contrast against every surface they are used on.
 *
 * `--ink-faint` carries pills, eyebrows, counts, lane names, empty states and breadcrumbs. It was
 * #7c8499 on a near-white page (3.49:1) and #737c93 on the dark one (as low as 4.12:1): about 900 text
 * elements below the 4.5:1 small text needs, found by the rendered contrast audit rather than by reading
 * the stylesheet. That audit (`tools/rendered`) is the browser-level guard; this test is the cheap one that
 * runs in `composer test` and fails the moment a token is edited below the line, without a browser.
 *
 * Contrast is computed from the stylesheet's own token values, so a retuned surface is checked too.
 */
final class DesignTokenContrastTest extends TestCase
{
    private const array TEXT = ['ink', 'ink-soft', 'ink-faint'];
    private const array SURFACES = ['bg', 'bg-sunk', 'surface', 'surface-alt'];
    private const float AA_SMALL_TEXT = 4.5;

    /** @return iterable<string, array{string, string, string}> */
    public static function pairs(): iterable
    {
        foreach (['light', 'dark'] as $scheme) {
            foreach (self::TEXT as $text) {
                foreach (self::SURFACES as $surface) {
                    yield "$scheme: $text on $surface" => [$scheme, $text, $surface];
                }
            }
        }
    }

    #[DataProvider('pairs')]
    public function testTextTokenMeetsAaOnSurface(string $scheme, string $text, string $surface): void
    {
        $tokens = $this->tokens($scheme);

        $ratio = self::contrast($tokens[$text], $tokens[$surface]);

        self::assertGreaterThanOrEqual(
            self::AA_SMALL_TEXT,
            $ratio,
            sprintf('%s: --%s (%s) on --%s (%s) is %.2f:1, below %.1f:1.', $scheme, $text, $tokens[$text], $surface, $tokens[$surface], $ratio, self::AA_SMALL_TEXT),
        );
    }

    /**
     * The Copy button used to sit at half opacity until hovered, which put its label at 2.36:1 and made the
     * only way to see it a hover. It is a plain control now; opacity must not creep back onto the resting state.
     */
    public function testCopyButtonIsNotFadedAtRest(): void
    {
        self::assertSame(0, $this->opacityDeclarations('.copy'), 'A faded resting Copy button fails contrast and is hover-only.');
    }

    /** The breadcrumb separators were 60% opacity (1.98:1). */
    public function testBreadcrumbSeparatorsAreNotFaded(): void
    {
        self::assertSame(0, $this->opacityDeclarations('.crumbs span'));
    }

    /** @return array<string, string> token name => #rrggbb */
    private function tokens(string $scheme): array
    {
        $css = $this->css();
        $pattern = $scheme === 'light'
            ? '~:root\s*\{(.*?)\n\}~s'
            : '~@media \(prefers-color-scheme: dark\)\s*\{\s*:root\s*\{(.*?)\n    \}~s';
        $found = preg_match($pattern, $css, $match);
        self::assertSame(1, $found, ucfirst($scheme) . ' :root block not found.');
        preg_match_all('~--([a-z-]+):\s*(#[0-9a-fA-F]{6})\s*;~', $match[1], $all, PREG_SET_ORDER);

        $tokens = [];
        foreach ($all as $row) {
            $tokens[$row[1]] = strtolower($row[2]);
        }
        foreach ([...self::TEXT, ...self::SURFACES] as $required) {
            self::assertArrayHasKey($required, $tokens, "--$required missing from the $scheme tokens.");
        }

        return $tokens;
    }

    /** How many `opacity:` declarations the rule has - `transition: opacity ...` is not one. */
    private function opacityDeclarations(string $selector): int
    {
        $count = preg_match_all('~(?<![-\w])opacity\s*:~', $this->rule($selector));
        self::assertIsInt($count);

        return $count;
    }

    /** The declarations of the first rule whose selector is exactly `$selector`. */
    private function rule(string $selector): string
    {
        $found = preg_match('~(?:^|\})\s*' . preg_quote($selector, '~') . '\s*\{([^}]*)\}~m', $this->css(), $match);
        self::assertSame(1, $found, "No rule for $selector in app.css.");

        return $match[1];
    }

    private function css(): string
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/templates/layout/app.css');
        self::assertIsString($css);

        return $css;
    }

    private static function contrast(string $foreground, string $background): float
    {
        [$hi, $lo] = [self::luminance($foreground), self::luminance($background)];
        if ($hi < $lo) {
            [$hi, $lo] = [$lo, $hi];
        }

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
