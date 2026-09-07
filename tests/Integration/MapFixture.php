<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use RuntimeException;

/**
 * Builds a throwaway repository with a real PHP file and a matching agent-map index.
 *
 * The source hash is computed from the file that is actually written, so a test
 * can prove the difference between "the map describes this file" and "the map
 * has been left behind" instead of asserting against a hash nobody verified.
 */
final class MapFixture
{
    public readonly string $root;

    public function __construct(string $prefix = 'agent_ui_fixture_')
    {
        $this->root = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/.agent-map', 0o775, true) && !is_dir($this->root . '/.agent-map')) {
            throw new RuntimeException('Unable to create fixture root: ' . $this->root);
        }
    }

    public function writeFile(string $relativePath, string $contents): string
    {
        $absolute = $this->root . '/' . $relativePath;
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create fixture directory: ' . $directory);
        }
        file_put_contents($absolute, $contents);

        return 'sha256:' . hash('sha256', $contents);
    }

    /**
     * @param list<array<string, mixed>> $files
     * @param list<array<string, mixed>> $relations
     */
    public function writeMap(array $files, array $relations = [], ?string $sourceDigest = null): void
    {
        $map = [
            'schema_version' => '2.0',
            'root' => $this->root,
            'backend' => 'simple-php-code-parser',
            'files' => $files,
            'relations' => $relations,
            'diagnostics' => [],
        ];
        if ($sourceDigest !== null) {
            $map['fingerprint'] = [
                'source_digest' => $sourceDigest,
                'analysis_digest' => $sourceDigest,
                'backend' => 'simple-php-code-parser',
                'tool_version' => 'fixture',
            ];
        }

        file_put_contents(
            $this->root . '/.agent-map/php-symbols.json',
            json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<array<string, mixed>> $methods
     *
     * @return array<string, mixed>
     */
    public static function classSymbol(string $name, string $fqn, int $lineStart, int $lineEnd, array $methods = []): array
    {
        return [
            'kind' => 'class',
            'name' => $name,
            'fqn' => $fqn,
            'line_start' => $lineStart,
            'line_end' => $lineEnd,
            'methods' => $methods,
            'extends' => [],
            'implements' => [],
            'parameters' => [],
            'native_return_type' => null,
            'phpdoc_return_type' => null,
            'resolved_return_type' => null,
            'attributes' => [],
            'reconciliation_status' => 'structural_only',
        ];
    }

    /** @return array<string, mixed> */
    public static function method(string $name, int $lineStart, int $lineEnd): array
    {
        return [
            'name' => $name,
            'visibility' => 'public',
            'line_start' => $lineStart,
            'line_end' => $lineEnd,
            'parameters' => [],
            'native_return_type' => 'void',
            'phpdoc_return_type' => null,
            'resolved_return_type' => null,
            'attributes' => [],
            'reconciliation_status' => 'structural_only',
        ];
    }

    public function remove(): void
    {
        $this->removeDir($this->root);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
