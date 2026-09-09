<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A public method on a consumer gateway or read model must have a reader.
 *
 * These classes exist to carry owner facts to a page. A public derivation that
 * nothing calls is not a small waste: it reads as part of the contract, so the
 * next author preserves it, tests around it, and reasons about a shape no human
 * ever sees. Three had accumulated before this test existed - two rank
 * derivations on a search hit and an index-path lookup left behind when its
 * caller was replaced - and nothing failed, because nothing was looking.
 *
 * What this test can and cannot see, stated rather than implied. Evidence of a
 * call is `->name(` or `::name(` appearing outside the declaring file, which
 * cannot resolve *which* class the receiver is. So a method is only checked
 * when its name is declared exactly once in the whole tree; a name two classes
 * share is reported as unresolvable instead of being counted as used, because
 * a guard that silently passes what it cannot see is the same defect it exists
 * to catch. Roughly half this surface is unresolvable today, and the honest
 * fix if that matters is a real call graph, not a cleverer string match.
 */
final class IntegrationSurfaceTest extends TestCase
{
    /** Methods a consumer never calls by name. */
    private const array EXEMPT = ['__construct', '__toString', '__invoke'];

    public function testEveryResolvablePublicIntegrationMethodHasACallerOutsideItsOwnFile(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = $this->sources($root);
        $declarations = $this->declarationCounts($sources);

        $unused = [];
        $resolvable = 0;
        $unresolvable = 0;

        foreach ($this->files($root . '/src/Integration') as $file) {
            foreach ($this->publicMethods($sources[$file]) as $method) {
                if (($declarations[$method] ?? 0) > 1) {
                    ++$unresolvable;
                    continue;
                }

                ++$resolvable;
                if (!$this->calledOutside($method, $file, $sources)) {
                    $unused[] = str_replace($root . '/', '', $file) . '::' . $method . '()';
                }
            }
        }

        self::assertSame(
            [],
            $unused,
            "These public methods have no caller outside their own file. Delete them, or make them private\n"
            . "if their only use is internal:\n  " . implode("\n  ", $unused),
        );

        // Without this the guard could quietly degrade to checking nothing -
        // every name becoming ambiguous would read exactly like every method
        // being used.
        self::assertGreaterThan(
            0,
            $resolvable,
            'The guard resolved no methods at all, so its silence means nothing.',
        );
        self::assertGreaterThan($unresolvable, $resolvable * 3, sprintf(
            'Only %d of %d public integration methods have names unique enough to check. '
            . 'That is too little coverage for this guard to be worth trusting.',
            $resolvable,
            $resolvable + $unresolvable,
        ));
    }

    /** @param array<string, string> $sources */
    private function calledOutside(string $method, string $file, array $sources): bool
    {
        foreach ($sources as $path => $contents) {
            if ($path === $file) {
                continue;
            }
            if (str_contains($contents, '->' . $method . '(') || str_contains($contents, '::' . $method . '(')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function publicMethods(string $source): array
    {
        preg_match_all('/\n    public function ([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches);

        return array_values(array_filter(
            $matches[1],
            static fn(string $method): bool => !in_array($method, self::EXEMPT, true),
        ));
    }

    /**
     * How many places declare each method name, so a shared name is never
     * mistaken for evidence about one particular class.
     *
     * @param array<string, string> $sources
     * @return array<string, int>
     */
    private function declarationCounts(array $sources): array
    {
        $counts = [];
        foreach ($sources as $contents) {
            preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $contents, $matches);
            foreach (array_unique($matches[1]) as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Everything that could plausibly call one: production code, the templates
     * that render it, and the tests that exercise it.
     *
     * @return array<string, string>
     */
    private function sources(string $root): array
    {
        $contents = [];
        foreach (['/src', '/templates', '/tests'] as $directory) {
            foreach ($this->files($root . $directory) as $file) {
                $source = file_get_contents($file);
                if (!is_string($source)) {
                    throw new RuntimeException('Unable to read source: ' . $file);
                }
                $contents[$file] = $source;
            }
        }

        return $contents;
    }

    /** @return list<string> */
    private function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        /** @var SplFileInfo $entry */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
