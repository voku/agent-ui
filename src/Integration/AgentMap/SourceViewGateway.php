<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

use Throwable;
use voku\AgentMap\Context\SourceMaterializer;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\FileEntry;

/**
 * Bounded, hash-verified windows of repository source for the Map surface.
 *
 * The UI does not read files it likes the look of. A window exists only for a
 * path agent-map indexed, is materialized by agent-map's own
 * `SourceMaterializer`, and is refused when the recorded hash no longer matches
 * the working tree. Staleness is therefore a reported state, not a rendering
 * accident, and the developer is told to refresh the map instead of being shown
 * code the rest of the page is not describing.
 */
final readonly class SourceViewGateway
{
    /** A file window never exceeds this many lines, however large the file is. */
    public const int MAXIMUM_FILE_LINES = 600;

    /** A search-result or symbol preview stays small enough to scan without scrolling away. */
    public const int MAXIMUM_PREVIEW_LINES = 40;

    private MapArtifactLocator $locator;

    public function __construct(string $projectRoot, ?MapArtifactLocator $locator = null)
    {
        $this->locator = $locator ?? new MapArtifactLocator($projectRoot);
    }

    /** A window centred on one line range, optionally padded with surrounding context. */
    public function slice(
        string $path,
        int $lineStart,
        int $lineEnd,
        int $context = 0,
        int $maximumLines = self::MAXIMUM_PREVIEW_LINES,
    ): SourceView {
        $context = max(0, $context);

        return $this->window(
            $path,
            max(1, $lineStart - $context),
            max($lineStart, $lineEnd) + $context,
            $maximumLines,
            $lineStart,
        );
    }

    /** A window over a file, centred on an optional focus line so deep links land on their code. */
    public function file(string $path, ?int $focusLine = null, int $maximumLines = self::MAXIMUM_FILE_LINES): SourceView
    {
        $maximumLines = max(1, $maximumLines);
        $start = 1;
        if ($focusLine !== null && $focusLine > $maximumLines) {
            $start = max(1, $focusLine - intdiv($maximumLines, 2));
        }

        return $this->window($path, $start, $start + $maximumLines - 1, $maximumLines, $focusLine);
    }

    private function window(string $path, int $lineStart, int $lineEnd, int $maximumLines, ?int $focusLine): SourceView
    {
        $index = $this->locator->loadIndex();
        if ($index === null) {
            return SourceView::unavailable($path, 'missing', 'No code map index found. Run "vendor/bin/agent-map build" first.');
        }

        $file = $this->fileEntry($index, $path);
        if ($file === null) {
            return SourceView::unavailable($path, 'not_indexed', sprintf('"%s" is not part of the indexed code map.', $path));
        }

        $maximumLines = max(1, $maximumLines);
        $lineStart = max(1, $lineStart);
        $lineEnd = max($lineStart, $lineEnd);
        $lineEnd = min($lineEnd, $lineStart + $maximumLines - 1);

        // One line past the window is requested and then dropped: agent-map clamps to
        // the real end of file, so an answer shorter than the request is the only
        // evidence available that there is nothing further down.
        try {
            $materialized = (new SourceMaterializer())->materialize(
                $this->locator->projectRoot,
                $file,
                $lineStart,
                $lineEnd + 1,
                false,
            );
        } catch (Throwable $exception) {
            // agent-map refuses to materialize source whose hash left the map behind.
            // That refusal is the honest answer, so it is surfaced as state.
            return SourceView::unavailable($path, 'stale', $exception->getMessage());
        }

        // Each materialized line keeps its own newline, so exploding produces one
        // trailing empty element unless the file ends without a final newline.
        // Popping exactly that element preserves genuinely blank source lines,
        // which a trailing trim would silently eat along with the count they carry.
        $texts = explode("\n", $materialized['content']);
        if ($texts[count($texts) - 1] === '') {
            array_pop($texts);
        }

        $hasMoreAfter = count($texts) > ($lineEnd - $materialized['start'] + 1);
        if ($hasMoreAfter) {
            array_pop($texts);
        }

        $lines = [];
        $number = $materialized['start'];
        foreach ($texts as $text) {
            $lines[] = new SourceLine(
                number: $number,
                text: rtrim($text, "\r"),
                focus: $focusLine !== null && $number === $focusLine,
            );
            ++$number;
        }

        return new SourceView(
            status: 'ready',
            path: $file->path,
            lines: $lines,
            lineStart: $materialized['start'],
            lineEnd: $lines === [] ? $materialized['start'] : $lines[count($lines) - 1]->number,
            hasMoreBefore: $materialized['start'] > 1,
            hasMoreAfter: $hasMoreAfter,
            sourceSha256: $file->sha256,
            symbols: $this->symbolsOf($file),
            focusLine: $focusLine,
        );
    }

    private function fileEntry(AgentMapIndex $index, string $path): ?FileEntry
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if ($normalized === '' || str_contains($normalized, '../')) {
            return null;
        }

        return $index->file($normalized);
    }

    /** @return list<array{id: string, kind: string, name: string, lineStart: int, lineEnd: int}> */
    private function symbolsOf(FileEntry $file): array
    {
        $symbols = [];
        foreach ($file->symbols as $symbol) {
            $symbols[] = [
                'id' => $symbol->id(),
                'kind' => $symbol->kind,
                'name' => $symbol->name,
                'lineStart' => $symbol->lineStart,
                'lineEnd' => $symbol->lineEnd,
            ];
            foreach ($symbol->methods as $method) {
                $symbols[] = [
                    'id' => $symbol->methodId($method),
                    'kind' => 'method',
                    'name' => $symbol->name . '::' . $method->name,
                    'lineStart' => $method->lineStart,
                    'lineEnd' => $method->lineEnd,
                ];
            }
        }

        usort(
            $symbols,
            static fn(array $left, array $right): int => $left['lineStart'] <=> $right['lineStart'] ?: strcmp($left['name'], $right['name']),
        );

        return $symbols;
    }
}
