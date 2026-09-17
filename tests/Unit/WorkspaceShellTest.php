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

    /** The four areas are the product hierarchy; a flat list returning would undo the change silently. */
    public function testNavigationIsGroupedByDeveloperIntentRatherThanFlat(): void
    {
        $header = $this->read('templates/layout/header.php');

        foreach (['Work', 'Knowledge', 'Code', 'Tools'] as $section) {
            self::assertStringContainsString("'" . $section . "' => [", $header);
        }
    }

    /** The task groups follow the story - where it stands, what was agreed, what was done, what proves it. */
    public function testTaskViewsAreGroupedByTheTaskStory(): void
    {
        $nav = $this->read('templates/layout/task-nav.php');

        foreach (['Summary', 'Intent', 'Execution', 'Evidence', 'Tools'] as $group) {
            self::assertStringContainsString("'" . $group . "' => [", $nav);
        }
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

    /** Core navigation must not depend on JavaScript. */
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
