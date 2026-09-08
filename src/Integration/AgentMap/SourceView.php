<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

/**
 * A bounded window of real repository source, verified against the map that indexed it.
 *
 * Every window carries the hash agent-map recorded for the file. That is the
 * difference between "here is the code" and "here is the code the map is
 * talking about": when the working tree has moved on, `status` is `stale` and no
 * source is rendered, because source that silently disagrees with the map is how
 * a developer ends up reading one function and editing another.
 *
 * The window reports what is above and below it rather than a total line count.
 * The map records symbol boundaries, not file lengths, so a total would have to
 * be guessed - and a guessed denominator reads exactly like a measured one.
 *
 * `stale` is claimed only when agent-map's own stale evidence names the file, and
 * `staleReason` carries the owner's word for why (`hash` or `missing`). A
 * materialization that fails for any other reason stays `unavailable`, so an
 * unexpected failure is still visible as one instead of being folded into a
 * diagnosis the map never made.
 */
final readonly class SourceView
{
    /**
     * @param list<SourceLine> $lines
     * @param list<array{id: string, kind: string, name: string, lineStart: int, lineEnd: int}> $symbols
     */
    public function __construct(
        public string $status,
        public string $path,
        public array $lines = [],
        public int $lineStart = 0,
        public int $lineEnd = 0,
        public bool $hasMoreBefore = false,
        public bool $hasMoreAfter = false,
        public ?string $sourceSha256 = null,
        public array $symbols = [],
        public ?int $focusLine = null,
        public ?string $failure = null,
        public ?string $staleReason = null,
    ) {
    }

    public static function unavailable(string $path, string $status, string $failure, ?string $staleReason = null): self
    {
        return new self(status: $status, path: $path, failure: $failure, staleReason: $staleReason);
    }

    public function isRendered(): bool
    {
        return $this->status === 'ready' && $this->lines !== [];
    }

    public function isBounded(): bool
    {
        return $this->hasMoreBefore || $this->hasMoreAfter;
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    public function shortSha(): ?string
    {
        if ($this->sourceSha256 === null) {
            return null;
        }

        $digest = str_starts_with($this->sourceSha256, 'sha256:')
            ? substr($this->sourceSha256, 7)
            : $this->sourceSha256;

        return substr($digest, 0, 12);
    }
}
