<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Search;

/**
 * What a developer typed into the one search box, split into where and what.
 *
 * The box accepts the command style the workbench epic sketches - `task UI-58`,
 * `/proposal provenance`, `symbol WorkflowProgressProjector` - because the
 * person typing should not have to know which package owns a concept. The first
 * word narrows the search only when it names a scope this UI actually has;
 * every other first word is simply part of what is being looked for, so
 * `memory rules` searches for both words rather than for a scope called
 * `memory`.
 *
 * `find` means "everywhere" and exists so that `/find memory` is a spelled-out
 * global search rather than a search for the word `find`.
 */
final readonly class SearchQuery
{
    public const string SCOPE_TASK = 'task';
    public const string SCOPE_FINDING = 'finding';
    public const string SCOPE_PROPOSAL = 'proposal';
    public const string SCOPE_GUIDANCE = 'guidance';
    public const string SCOPE_SYMBOL = 'symbol';
    public const string SCOPE_CODE = 'code';
    public const string SCOPE_PROMPT = 'prompt';

    /** @var list<string> */
    public const array SCOPES = [
        self::SCOPE_TASK,
        self::SCOPE_FINDING,
        self::SCOPE_PROPOSAL,
        self::SCOPE_GUIDANCE,
        self::SCOPE_SYMBOL,
        self::SCOPE_CODE,
        self::SCOPE_PROMPT,
    ];

    /**
     * Long enough for any identifier, path or sentence a developer would type,
     * short enough that a pasted log line is refused instead of becoming a
     * query nobody meant.
     */
    public const int MAXIMUM_LENGTH = 200;

    private function __construct(
        public string $raw,
        public ?string $scope,
        public string $term,
        public bool $tooLong,
    ) {
    }

    public static function parse(string $raw): self
    {
        $raw = trim($raw);
        if (mb_strlen($raw) > self::MAXIMUM_LENGTH) {
            return new self($raw, null, '', true);
        }

        // A leading slash is a command marker only when a command follows it.
        // `/etc/hosts` is a path someone is looking for, and stripping its slash
        // would search for something else.
        $candidate = str_starts_with($raw, '/') ? substr($raw, 1) : $raw;
        $parts = preg_split('/\s+/', $candidate, 2);
        $first = mb_strtolower($parts[0] ?? '');
        $rest = trim($parts[1] ?? '');

        if (in_array($first, self::SCOPES, true)) {
            return new self($raw, $first, $rest, false);
        }
        if ($first === 'find') {
            return new self($raw, null, $rest, false);
        }

        return new self($raw, null, $raw, false);
    }

    /**
     * Lowercased words, all of which a record must contain.
     *
     * @return list<string>
     */
    public function words(): array
    {
        if ($this->term === '') {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', mb_strtolower($this->term)) ?: [],
            static fn(string $word): bool => $word !== '',
        ));
    }

    /** Nothing to do: no scope and no words. */
    public function isEmpty(): bool
    {
        return $this->scope === null && $this->term === '';
    }

    /**
     * Code is searched by an owner query that needs something to look for.
     *
     * Listing every symbol or chunk is not a search, so these two scopes ask for
     * a term instead of dumping the index.
     */
    public function needsTerm(): bool
    {
        return in_array($this->scope, [self::SCOPE_SYMBOL, self::SCOPE_CODE], true) && $this->term === '';
    }
}
