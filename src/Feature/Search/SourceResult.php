<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Search;

use InvalidArgumentException;

/**
 * What one owner said when asked, kept apart from what the others said.
 *
 * Three answers are possible and they are different facts: the owner returned
 * records, the owner looked and found none, or the owner could not look. A
 * search page that rendered the third as the second would tell a developer
 * there is nothing to find in a project whose index simply was not built - so
 * "unavailable" carries the owner's own reason and is never an empty list.
 */
final readonly class SourceResult
{
    public const string FOUND = 'found';
    public const string NO_MATCHES = 'no_matches';
    public const string UNAVAILABLE = 'unavailable';

    /**
     * @param list<SearchHit> $hits what is shown
     * @param int $matched how many records matched; with `$exact` false, at least this many
     */
    private function __construct(
        public string $scope,
        public string $owner,
        public string $label,
        public string $status,
        public array $hits,
        public int $matched,
        public bool $exact,
        public ?string $reason,
        public ?string $caveat = null,
    ) {
    }

    /** @param list<SearchHit> $hits */
    public static function found(string $scope, string $owner, string $label, array $hits, int $matched, bool $exact = true): self
    {
        if ($hits === []) {
            throw new InvalidArgumentException('A source that found records must show at least one: ' . $label . '.');
        }

        return new self($scope, $owner, $label, self::FOUND, $hits, $matched, $exact, null);
    }

    public static function noMatches(string $scope, string $owner, string $label): self
    {
        return new self($scope, $owner, $label, self::NO_MATCHES, [], 0, true, null);
    }

    public static function unavailable(string $scope, string $owner, string $label, string $reason): self
    {
        if (trim($reason) === '') {
            // An unavailable source with no reason is indistinguishable from a
            // bug in this page, so it is refused rather than rendered blank.
            throw new InvalidArgumentException('An unavailable source must say why: ' . $label . '.');
        }

        return new self($scope, $owner, $label, self::UNAVAILABLE, [], 0, true, $reason);
    }

    /**
     * The same answer, with the owner's warning that it may not be complete.
     *
     * A stale index is still searched - refusing would be unhelpful - but "no
     * matches" from an index the owner calls stale is a weaker claim than "no
     * matches" from a fresh one, and the page has to be able to say so. Only an
     * answer the owner gave can carry a caveat; an unavailable source has no
     * answer to qualify.
     */
    public function withCaveat(string $caveat): self
    {
        if ($this->status === self::UNAVAILABLE || trim($caveat) === '') {
            return $this;
        }

        return new self($this->scope, $this->owner, $this->label, $this->status, $this->hits, $this->matched, $this->exact, $this->reason, $caveat);
    }

    /** True when more records matched than are shown. */
    public function isTruncated(): bool
    {
        return $this->status === self::FOUND && ($this->matched > count($this->hits) || !$this->exact);
    }
}
