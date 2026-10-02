<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Search;

use Exception;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentMap\CodeSearchGateway;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\OperatingPromptCatalogGateway;

/**
 * Asks each owner's public read model the same question and keeps the answers apart.
 *
 * This is composition, not an index. There is no stored corpus, no relevance
 * score and no ordering of its own: records come back in the order their owner
 * published them, grouped under that owner. Where an owner offers a search of
 * its own (agent-map) the term goes to it; where an owner publishes typed lists
 * and no search (tasks, Findings, Proposals, recipes) the match is a literal,
 * case-insensitive filter over the fields named in each hit - identifiers and
 * the one descriptive line - so the page can say exactly why a record is shown.
 *
 * If a semantic search over those records is ever wanted, it is an owner API to
 * request from the owner, not a matching policy to grow here.
 */
final readonly class GlobalSearch
{
    /** Per source when several are shown together. */
    public const int COMBINED_LIMIT = 8;

    /** Per source when the developer narrowed to one scope. */
    public const int SCOPED_LIMIT = 25;

    private const int EXCERPT_LENGTH = 160;

    public function __construct(
        private BoardProjectionGateway $board,
        private LearningCatalogGateway $learning,
        private MapProjectionGateway $map,
        private CodeSearchGateway $code,
        private OperatingPromptCatalogGateway $prompts,
    ) {
    }

    /**
     * @return list<SourceResult>
     */
    public function search(SearchQuery $query): array
    {
        if ($query->isEmpty() || $query->needsTerm() || $query->tooLong) {
            return [];
        }

        $limit = $query->scope === null ? self::COMBINED_LIMIT : self::SCOPED_LIMIT;
        $results = [];

        foreach ($this->sources() as $scopes => $source) {
            $handles = explode('|', $scopes);
            // `code` is searched only when asked for: agent-map's symbol index
            // already answers the combined view, and chunk search returns the
            // same records again with previews.
            $wanted = $query->scope === null
                ? $handles[0] !== SearchQuery::SCOPE_CODE
                : in_array($query->scope, $handles, true);
            if ($wanted) {
                // The scope a failure is reported under is the one asked for, so a
                // broken Learning root under `guidance` is labelled Guidance.
                $reported = $query->scope === SearchQuery::SCOPE_GUIDANCE && in_array(SearchQuery::SCOPE_GUIDANCE, $handles, true)
                    ? SearchQuery::SCOPE_GUIDANCE
                    : $handles[0];
                $results[] = $this->guarded($reported, $source, $query, $limit);
            }
        }

        return $results;
    }

    /**
     * @return array<string, callable(SearchQuery, int): SourceResult>
     */
    private function sources(): array
    {
        return [
            SearchQuery::SCOPE_TASK => $this->tasks(...),
            SearchQuery::SCOPE_FINDING => $this->findings(...),
            SearchQuery::SCOPE_PROPOSAL . '|' . SearchQuery::SCOPE_GUIDANCE => $this->proposals(...),
            SearchQuery::SCOPE_SYMBOL => $this->symbols(...),
            SearchQuery::SCOPE_CODE => $this->codeChunks(...),
            SearchQuery::SCOPE_PROMPT => $this->recipes(...),
        ];
    }

    /**
     * One owner failing is that owner's answer, not the page's.
     *
     * The failure is shown, with the owner's message, under that owner's name -
     * not swallowed - and the other sources still answer. Only `Exception` is
     * caught: a `TypeError` or other `Error` is a defect in this page, and
     * rendering it as "tasks unavailable" would hide it.
     *
     * @param callable(SearchQuery, int): SourceResult $source
     */
    private function guarded(string $scope, callable $source, SearchQuery $query, int $limit): SourceResult
    {
        try {
            return $source($query, $limit);
        } catch (Exception $exception) {
            error_log('agent-ui search source ' . $scope . ' failed: ' . $exception->getMessage());
            [$owner, $label] = self::identity($scope);

            return SourceResult::unavailable($scope, $owner, $label, $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class);
        }
    }

    /** @return array{string, string} */
    private static function identity(string $scope): array
    {
        return match ($scope) {
            SearchQuery::SCOPE_TASK => ['agent-kanban', 'Tasks'],
            SearchQuery::SCOPE_FINDING => ['agent-learning', 'Findings'],
            SearchQuery::SCOPE_PROPOSAL => ['agent-learning', 'Proposals'],
            SearchQuery::SCOPE_GUIDANCE => ['agent-learning', 'Guidance'],
            SearchQuery::SCOPE_SYMBOL => ['agent-map', 'Symbols'],
            SearchQuery::SCOPE_CODE => ['agent-map', 'Code'],
            default => ['agent-recall-compiler', 'Prompt recipes'],
        };
    }

    private function tasks(SearchQuery $query, int $limit): SourceResult
    {
        [$owner, $label] = self::identity(SearchQuery::SCOPE_TASK);

        $snapshot = $this->board->board();
        $cards = $snapshot->cards;
        $titles = [];
        // A project can hold several boards, and the default one is only one of
        // them. Searching it alone would answer "no such task" for a task that
        // exists on a board the developer was not looking at.
        foreach ($snapshot->boards as $summary) {
            $titles[$summary->id] = $summary->title;
            if (!$summary->active) {
                array_push($cards, ...$this->board->board($summary->id)->cards);
            }
        }
        $multiple = count($snapshot->boards) > 1;

        $hits = [];
        $matched = 0;
        foreach ($cards as $card) {
            $fields = $this->match($query, ['id' => $card->id, 'title' => $card->title]);
            if ($fields === null) {
                continue;
            }
            $matched++;
            if (count($hits) < $limit) {
                $detail = $card->lane . ' · ' . $card->status;
                if ($multiple && $card->boardId !== null && isset($titles[$card->boardId])) {
                    $detail .= ' · board ' . $titles[$card->boardId];
                }
                $hits[] = new SearchHit($card->id, $card->title, '/task/' . rawurlencode($card->id), $detail, $fields);
            }
        }

        return $this->result(SearchQuery::SCOPE_TASK, $owner, $label, $hits, $matched);
    }

    private function findings(SearchQuery $query, int $limit): SourceResult
    {
        [$owner, $label] = self::identity(SearchQuery::SCOPE_FINDING);
        if (!$this->learning->isAvailable()) {
            return SourceResult::unavailable(SearchQuery::SCOPE_FINDING, $owner, $label, 'This project has no Learning root, so agent-learning has nothing to search.');
        }

        $hits = [];
        $matched = 0;
        foreach ($this->learning->findings() as $finding) {
            $fields = $this->match($query, [
                'id' => $finding->id,
                'task' => $finding->taskId,
                'observation' => $finding->observation,
            ]);
            if ($fields === null) {
                continue;
            }
            $matched++;
            if (count($hits) < $limit) {
                $hits[] = new SearchHit(
                    $finding->id,
                    $this->excerpt($finding->observation),
                    '/knowledge/findings/' . rawurlencode($finding->id),
                    'task ' . $finding->taskId . ' · ' . $finding->status,
                    $fields,
                );
            }
        }

        return $this->result(SearchQuery::SCOPE_FINDING, $owner, $label, $hits, $matched);
    }

    private function proposals(SearchQuery $query, int $limit): SourceResult
    {
        $guidanceOnly = $query->scope === SearchQuery::SCOPE_GUIDANCE;
        $scope = $guidanceOnly ? SearchQuery::SCOPE_GUIDANCE : SearchQuery::SCOPE_PROPOSAL;
        $label = $guidanceOnly ? 'Guidance' : 'Proposals';
        $owner = 'agent-learning';
        if (!$this->learning->isAvailable()) {
            return SourceResult::unavailable($scope, $owner, $label, 'This project has no Learning root, so agent-learning has nothing to search.');
        }

        $hits = [];
        $matched = 0;
        foreach ($this->learning->proposals() as $proposal) {
            $fields = $this->match($query, [
                'id' => $proposal->id,
                'target' => $proposal->target ?? '',
                'action' => $proposal->action,
                'reason' => $proposal->reason,
            ]);
            if ($fields === null) {
                continue;
            }

            // Whether a Proposal is also guidance is agent-learning's call, made
            // by its own projection - not a rule re-derived here from the
            // proposal's target type.
            $isGuidance = $this->learning->guidance($proposal->id) !== null;
            if ($guidanceOnly && !$isGuidance) {
                continue;
            }

            $matched++;
            if (count($hits) >= $limit) {
                continue;
            }
            $proposalHref = '/knowledge/proposals/' . rawurlencode($proposal->id);
            $guidanceHref = '/knowledge/guidance/' . rawurlencode($proposal->id);
            $detail = $proposal->status . ' · ' . $proposal->action . ($proposal->target !== null ? ' · ' . $proposal->target : '');
            $hits[] = $guidanceOnly
                ? new SearchHit($proposal->id, $this->excerpt($proposal->reason), $guidanceHref, $detail, $fields, [['label' => 'Proposal', 'href' => $proposalHref]])
                : new SearchHit(
                    $proposal->id,
                    $this->excerpt($proposal->reason),
                    $proposalHref,
                    $detail,
                    $fields,
                    $isGuidance ? [['label' => 'Guidance', 'href' => $guidanceHref]] : [],
                );
        }

        return $this->result($scope, $owner, $label, $hits, $matched);
    }

    private function symbols(SearchQuery $query, int $limit): SourceResult
    {
        [$owner, $label] = self::identity(SearchQuery::SCOPE_SYMBOL);

        $readiness = $this->map->readiness();
        if (!$readiness->isUsable()) {
            return SourceResult::unavailable(
                SearchQuery::SCOPE_SYMBOL,
                $owner,
                $label,
                'agent-map reports its index as ' . $readiness->status . ($readiness->failure !== null ? ': ' . $readiness->failure : '') . '.',
            );
        }

        // One past the limit is how this page learns there is more without being
        // handed the whole index. agent-map caps what it returns, so the count it
        // gives back is a lower bound once it reaches the cap.
        $caveat = $readiness->isReady()
            ? null
            : 'agent-map reports its index as ' . $readiness->status . ' (' . count($readiness->staleEntries) . ' file(s) changed since it was built), so this list may be incomplete or out of date.';
        $found = $this->map->search($query->term, $limit + 1);
        if ($found === []) {
            return SourceResult::noMatches(SearchQuery::SCOPE_SYMBOL, $owner, $label)->withCaveat((string) $caveat);
        }

        $hits = [];
        foreach (array_slice($found, 0, $limit) as $symbol) {
            // The fully qualified name is the readable identity; the raw index id
            // (`class:Foo\Bar`) is the same string with a kind prefix, and shows
            // up in the link target.
            $hits[] = new SearchHit(
                $symbol->fqn,
                '',
                '/map/symbol?id=' . rawurlencode($symbol->id),
                $symbol->kind . ' · ' . $symbol->file . ':' . $symbol->lineStart,
                ['agent-map query'],
            );
        }

        return SourceResult::found(SearchQuery::SCOPE_SYMBOL, $owner, $label, $hits, count($found), count($found) <= $limit)
            ->withCaveat((string) $caveat);
    }

    private function codeChunks(SearchQuery $query, int $limit): SourceResult
    {
        [$owner, $label] = self::identity(SearchQuery::SCOPE_CODE);

        $readiness = $this->code->readiness();
        if (!$readiness->isUsable()) {
            // agent-map files a machine identifier (`map_missing`) under `reason`
            // and the sentence that explains it under `failure`. The sentence
            // leads; the identifier stays beside it, because it is the owner's
            // own name for the condition and what its recovery docs search for.
            $sentence = $readiness->failure ?? 'agent-map reports its search index as ' . $readiness->status . '.';

            return SourceResult::unavailable(
                SearchQuery::SCOPE_CODE,
                $owner,
                $label,
                $readiness->reason !== null && $readiness->reason !== '' ? $sentence . ' (' . $readiness->reason . ')' : $sentence,
            );
        }

        $result = $this->code->search($query->term, $limit + 1, 0);
        if ($result->failure !== null) {
            return SourceResult::unavailable(SearchQuery::SCOPE_CODE, $owner, $label, $result->failure);
        }
        $caveats = [];
        if (!$readiness->isReady()) {
            $caveats[] = 'agent-map reports its search index as ' . $readiness->status . '.';
        }
        if ($result->degraded) {
            $caveats[] = 'agent-map answered in a degraded mode' . ($result->degradedReason !== null ? ': ' . $result->degradedReason : '') . '.';
        }
        $caveat = implode(' ', $caveats) . ($caveats !== [] ? ' This list may be incomplete or out of date.' : '');
        if ($result->hits === []) {
            return SourceResult::noMatches(SearchQuery::SCOPE_CODE, $owner, $label)->withCaveat($caveat);
        }

        $hits = [];
        foreach (array_slice($result->hits, 0, $limit) as $hit) {
            $hits[] = new SearchHit(
                $hit->symbolId,
                $hit->symbolName,
                '/map/source?path=' . rawurlencode($hit->file) . '&line=' . $hit->lineStart,
                $hit->kind . ' · ' . $hit->file . ':' . $hit->lineStart . '-' . $hit->lineEnd,
                ['agent-map search'],
                [['label' => 'Symbol', 'href' => '/map/symbol?id=' . rawurlencode($hit->symbolId)]],
            );
        }

        return SourceResult::found(SearchQuery::SCOPE_CODE, $owner, $label, $hits, count($result->hits), count($result->hits) <= $limit)
            ->withCaveat($caveat);
    }

    private function recipes(SearchQuery $query, int $limit): SourceResult
    {
        [$owner, $label] = self::identity(SearchQuery::SCOPE_PROMPT);

        $hits = [];
        $matched = 0;
        foreach ($this->prompts->recipes() as $recipe) {
            $fields = $this->match($query, [
                'id' => $recipe->id,
                'title' => $recipe->title,
                'description' => $recipe->description,
            ]);
            if ($fields === null) {
                continue;
            }
            $matched++;
            if (count($hits) < $limit) {
                // Recipes are chosen by POST on the workbench, so there is no
                // per-recipe URL to link to; the hit says where to choose it.
                $hits[] = new SearchHit($recipe->id, $recipe->title, '/prompts', 'select it on the Prompt Workbench · ' . $recipe->purpose, $fields);
            }
        }

        return $this->result(SearchQuery::SCOPE_PROMPT, $owner, $label, $hits, $matched);
    }

    /** @param list<SearchHit> $hits */
    private function result(string $scope, string $owner, string $label, array $hits, int $matched): SourceResult
    {
        return $hits === []
            ? SourceResult::noMatches($scope, $owner, $label)
            : SourceResult::found($scope, $owner, $label, $hits, $matched);
    }

    /**
     * The names of the fields the words were found in, or null when the record does not match.
     *
     * Every word must appear somewhere in the record's searched fields, and a
     * browse (no words) matches everything. The fields are named so the page
     * can show them; nothing here scores or orders.
     *
     * @param array<string, string> $fields
     * @return list<string>|null
     */
    private function match(SearchQuery $query, array $fields): ?array
    {
        $words = $query->words();
        if ($words === []) {
            return [];
        }

        $lowered = array_map(mb_strtolower(...), $fields);
        $haystack = implode("\n", $lowered);
        foreach ($words as $word) {
            if (!str_contains($haystack, $word)) {
                return null;
            }
        }

        $matchedIn = [];
        foreach ($lowered as $name => $value) {
            foreach ($words as $word) {
                if (str_contains($value, $word)) {
                    $matchedIn[] = $name;
                    break;
                }
            }
        }

        return $matchedIn;
    }

    private function excerpt(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($line) > self::EXCERPT_LENGTH ? mb_substr($line, 0, self::EXCERPT_LENGTH - 1) . '…' : $line;
    }
}
