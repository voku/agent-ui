<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The shell groups routes by the question being asked; it must not lose any.
 *
 * A flat `Overview | Setup | Prompts | Board | Map | Knowledge` row made every
 * concept a peer, so choosing where to click required knowing which package owned
 * the answer. The same was true of the ten equal task buttons, where "Edit card"
 * sat beside "Workflow" as though a developer weighs them alike.
 *
 * Regrouping navigation is the easiest possible way to silently drop a
 * destination, so the guarantee worth pinning is not that the new labels exist -
 * it is that the set of reachable routes did not shrink.
 */
final class WorkspaceShellTest extends TestCase
{
    /** Every route the flat primary nav reached, before the grouping. */
    private const PRIMARY_ROUTES = ['/', '/setup', '/prompts', '/board', '/map', '/knowledge'];

    /** Every task view the flat button row reached, before the grouping. */
    private const TASK_VIEWS = [
        '', '/progress', '/contract', '/edit', '/context',
        '/work', '/evidence', '/history', '/prompts', '/learning',
    ];

    /** Grouping is a rewrite of the link table, so the six destinations are listed and checked one by one. */
    public function testThePrimaryNavigationStillReachesEveryRouteItReachedBefore(): void
    {
        $header = $this->read('templates/layout/header.php');

        foreach (self::PRIMARY_ROUTES as $route) {
            self::assertStringContainsString(
                "['" . $route . "',",
                $header,
                'Regrouping must not drop ' . $route,
            );
        }
    }

    /** The same guarantee for the ten task views, whose suffixes are the deep links people have bookmarked. */
    public function testEveryTaskViewSurvivesTheGrouping(): void
    {
        $nav = $this->read('templates/layout/task-nav.php');

        foreach (self::TASK_VIEWS as $suffix) {
            self::assertStringContainsString(
                "'" . $suffix . "' =>",
                $nav,
                'Regrouping must not drop the task view ' . ($suffix === '' ? '(root)' : $suffix),
            );
        }
    }

    /**
     * Workflow work leads; the supporting tools sit behind one native disclosure.
     *
     * Every area used to be a peer in the masthead. Only the routes a governed
     * task is worked through stay primary; the rest must still be reachable
     * without script, which a <details> disclosure guarantees.
     */
    public function testPrimaryNavigationLeadsWithWorkflowWorkAndDisclosesTheTools(): void
    {
        $header = $this->read('templates/layout/header.php');

        $primary = substr($header, (int) strpos($header, '$primaryLinks = ['), 400);
        $primary = substr($primary, 0, (int) strpos($primary, '];'));
        foreach (["['/',", "['/board',", "['/knowledge',"] as $route) {
            self::assertStringContainsString($route, $primary);
        }
        foreach (["['/map',", "['/prompts',", "['/commands',", "['/setup',"] as $route) {
            self::assertStringNotContainsString($route, $primary, $route . ' is a supporting tool, not primary navigation');
        }
        self::assertStringContainsString('<details class="workspace-nav__more"', $header);
        // Closed by default (open it would overlay the page); the summary carries the current-page cue.
        self::assertStringContainsString("\$secondaryCurrent ? ' workspace-nav__summary--current' : ''", $header);
    }

    /** The task story is the primary row; context detail and tools sit under "More". */
    public function testTaskViewsLeadWithTheTaskStory(): void
    {
        $nav = $this->read('templates/layout/task-nav.php');

        $primary = substr($nav, (int) strpos($nav, '$taskNavPrimary = ['));
        $primary = substr($primary, 0, (int) strpos($primary, '];'));
        foreach (["'' =>", "'/progress' =>", "'/contract' =>", "'/work' =>", "'/evidence' =>", "'/history' =>"] as $view) {
            self::assertStringContainsString($view, $primary);
        }
        self::assertStringContainsString('<details class="task-nav__more"', $nav);
        self::assertStringContainsString("\$taskNavMoreCurrent ? ' open' : ''", $nav);
    }

    /**
     * Where you are is announced to assistive technology and shown without relying on colour.
     *
     * `aria-current` alone would leave a sighted reader with only a hue, and a hue alone
     * would leave a screen reader with nothing; both cues have to be present.
     */
    public function testTheCurrentDestinationIsAnnouncedAndNotSignalledByColourAlone(): void
    {
        $header = $this->read('templates/layout/header.php');
        $nav = $this->read('templates/layout/task-nav.php');
        $css = $this->read('templates/layout/app.css');

        self::assertStringContainsString('aria-current="page"', $header);
        self::assertStringContainsString('aria-current="page"', $nav);
        // Colour plus weight is not enough on its own; the current item keeps a shape cue.
        self::assertMatchesRegularExpression(
            '/\.workspace-nav__links a\[aria-current="page"\][^}]*box-shadow/s',
            $css,
        );
        self::assertMatchesRegularExpression('/\.btn--current\s*\{[^}]*box-shadow/s', $css);
    }

    /**
     * The group name is announced, so it must not also print into the visible label.
     *
     * Without this rule the prefix renders as literal text on every link, which is
     * how a screen-reader affordance becomes a visual defect.
     */
    public function testTheAnnouncedGroupPrefixIsHiddenFromSightedReaders(): void
    {
        $css = $this->read('templates/layout/app.css');
        $header = $this->read('templates/layout/header.php');
        $nav = $this->read('templates/layout/task-nav.php');

        self::assertStringContainsString('visually-hidden', $header);
        self::assertStringContainsString('visually-hidden', $nav);
        self::assertMatchesRegularExpression('/\.visually-hidden\s*\{[^}]*clip-path:\s*inset\(50%\)/s', $css);
    }

    /**
     * The task identity column keeps a floor, or a phone breaks the title per character.
     *
     * With a bare `auto` second column the facts took the width at 390px, the
     * identity column collapsed toward zero, and `overflow-wrap: anywhere` then
     * set the task title one letter per line. Rendered at that width it was
     * unmistakable; no assertion about markup would have noticed.
     */
    public function testTheTaskContextIdentityColumnCannotCollapse(): void
    {
        $css = $this->read('templates/layout/app.css');

        self::assertMatchesRegularExpression(
            '/\.task-context\s*\{[^}]*grid-template-columns:\s*minmax\(min\(100%,\s*\d+px\),\s*1fr\)/s',
            $css,
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 640px\) \{[^}]*\.task-context \{ grid-template-columns: minmax\(0, 1fr\); \}/s',
            $css,
        );
    }

    /** Owner history details may contain unbroken hashes; they must wrap on a phone instead of widening the page. */
    public function testTimelineDetailsWrapLongOwnerDigests(): void
    {
        $css = $this->read('templates/layout/app.css');

        self::assertMatchesRegularExpression(
            '/\.timeline__detail\s*\{[^}]*overflow-wrap:\s*anywhere/s',
            $css,
        );
    }

    /** Core navigation must not depend on JavaScript. */
    /**
     * Every custom property the stylesheet reads is one the stylesheet defines.
     *
     * A `var(--name)` that resolves to nothing does not fall back and does not
     * warn: the browser drops the whole declaration, so the rule is simply
     * absent from the rendered page. That is how a separator written as
     * `var(--line)` - the palette calls it `--rule` - shipped looking exactly
     * like a rule nobody had written yet.
     */
    public function testEveryCustomPropertyTheStylesheetReadsIsOneItDefines(): void
    {
        $css = $this->read('templates/layout/app.css');

        preg_match_all('/--[A-Za-z0-9-]+(?=\s*:)/', $css, $defined);
        preg_match_all('/var\(\s*(--[A-Za-z0-9-]+)/', $css, $used);

        $undefined = array_values(array_unique(array_diff($used[1], $defined[0])));

        self::assertSame([], $undefined, 'these custom properties are read but never defined: ' . implode(', ', $undefined));
    }

    public function testTheShellUsesNoScript(): void
    {
        foreach (['templates/layout/header.php', 'templates/layout/task-nav.php'] as $file) {
            $contents = $this->read($file);
            self::assertStringNotContainsString('<script', $contents);
            self::assertStringNotContainsString('onclick', $contents);
        }
    }

    /** Reads a template or stylesheet from the repository root, asserting it exists. */
    private function read(string $relative): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertIsString($contents);

        return $contents;
    }
}
