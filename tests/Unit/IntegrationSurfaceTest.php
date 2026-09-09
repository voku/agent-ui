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
 * Scope is deliberately narrow. This checks the `Integration/` boundary, where
 * the surface is owner-facing and every method should be traceable to a
 * template or a Feature. It matches on the method name appearing anywhere
 * outside its own file, so it is a floor rather than a proof: a method
 * genuinely used only inside its own class should be private, which is exactly
 * the change this test asks for.
 */
final class IntegrationSurfaceTest extends TestCase
{
    /** Methods a consumer never calls by name. */
    private const array EXEMPT = ['__construct', '__toString', '__invoke'];

    public function testEveryPublicIntegrationMethodHasACallerOutsideItsOwnFile(): void
    {
        $root = dirname(__DIR__, 2);
        $haystack = $this->sources($root);

        $unused = [];
        foreach ($this->files($root . '/src/Integration') as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                throw new RuntimeException('Unable to read integration source: ' . $file);
            }

            preg_match_all('/\n    public function ([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $source, $matches);
            foreach ($matches[1] as $method) {
                if (in_array($method, self::EXEMPT, true)) {
                    continue;
                }

                $called = false;
                foreach ($haystack as $path => $contents) {
                    if ($path === $file) {
                        continue;
                    }
                    if (str_contains($contents, $method . '(')) {
                        $called = true;
                        break;
                    }
                }

                if (!$called) {
                    $unused[] = str_replace($root . '/', '', $file) . '::' . $method . '()';
                }
            }
        }

        self::assertSame(
            [],
            $unused,
            "These public methods have no caller outside their own file. Delete them, or make them private if\n"
            . "their only use is internal:\n  " . implode("\n  ", $unused),
        );
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
