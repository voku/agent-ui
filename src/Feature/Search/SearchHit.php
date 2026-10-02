<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Search;

/**
 * One record an owner published, and the existing page that shows it.
 *
 * `$matchedIn` names the fields the words were found in, because "why is this
 * here" has to have an answer that is not a ranking score: the UI does not rank.
 */
final readonly class SearchHit
{
    /**
     * @param list<string> $matchedIn
     * @param list<array{label: string, href: string}> $alsoAt
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $href,
        public string $detail,
        public array $matchedIn = [],
        public array $alsoAt = [],
    ) {
    }
}
