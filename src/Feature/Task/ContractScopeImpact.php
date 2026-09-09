<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Task;

use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\Transparency\ApprovedScope;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;

/**
 * What the declared Contract scope reaches, composed from two owner answers.
 *
 * agent-map decides what an indexed file's impact is, its depth bound and its
 * uncertainty. agent-loop's `ApprovedScope` decides whether a reached path is
 * inside the approved boundary - the same rule the Git-observed scope drift on
 * the task page already uses, so the two cannot drift apart over a trailing
 * slash. This class picks neither: it asks both and counts what came back.
 *
 * Reaching outside the declared scope is an observation, never a verdict. The
 * map is bounded and may be behind the working tree, and a Contract can be
 * completely right about a change that a caller elsewhere will still notice.
 */
final readonly class ContractScopeImpact
{
    /** Scope entries analysed per page; a Contract longer than this is reported as bounded. */
    public const int MAXIMUM_ENTRIES = 25;

    /** Reached paths listed per entry. */
    public const int MAXIMUM_LISTED_PER_ENTRY = 8;

    /**
     * @param list<ContractScopeImpactEntry> $entries
     * @param list<string> $filesOutsideScope the union across entries, sorted and bounded
     */
    private function __construct(
        public bool $available,
        public array $entries,
        public array $filesOutsideScope,
        public int $filesOutsideScopeCount,
        public int $indexedEntryCount,
        public int $unindexedEntryCount,
        public bool $entriesTruncated,
        public bool $anyImpactTruncated,
        public bool $anyUncertain,
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, [], [], 0, 0, 0, false, false, false);
    }

    public static function compose(MapProjectionGateway $map, ?TaskContract $contract): self
    {
        if ($contract === null || $contract->scope === []) {
            return self::unavailable();
        }

        $scope = ApprovedScope::fromContract($contract);
        $analysed = array_slice($contract->scope, 0, self::MAXIMUM_ENTRIES);

        $entries = [];
        $indexed = 0;
        $unindexed = 0;
        $anyTruncated = false;
        $anyUncertain = false;
        /** @var array<string, true> $outsideUnion */
        $outsideUnion = [];

        foreach ($analysed as $path) {
            $snapshot = $map->fileImpact($path);
            if ($snapshot === null) {
                ++$unindexed;
                $entries[] = new ContractScopeImpactEntry($path, false, 0, [], 0, 0, false, false);
                continue;
            }

            ++$indexed;
            $partition = $scope->partition($snapshot->files());
            foreach ($partition['outside'] as $outside) {
                $outsideUnion[$outside] = true;
            }
            $uncertain = $snapshot->uncertainCount() > 0;
            $anyTruncated = $anyTruncated || $snapshot->truncated;
            $anyUncertain = $anyUncertain || $uncertain;

            $entries[] = new ContractScopeImpactEntry(
                path: $path,
                indexed: true,
                seedCount: $snapshot->seedCount,
                reachedOutsideScope: array_slice($partition['outside'], 0, self::MAXIMUM_LISTED_PER_ENTRY),
                reachedOutsideScopeCount: count($partition['outside']),
                reachedInsideScopeCount: count($partition['inside']),
                uncertain: $uncertain,
                truncated: $snapshot->truncated,
            );
        }

        $union = array_keys($outsideUnion);
        sort($union, SORT_STRING);

        return new self(
            available: true,
            entries: $entries,
            filesOutsideScope: $union,
            filesOutsideScopeCount: count($union),
            indexedEntryCount: $indexed,
            unindexedEntryCount: $unindexed,
            entriesTruncated: count($contract->scope) > count($analysed),
            anyImpactTruncated: $anyTruncated,
            anyUncertain: $anyUncertain,
        );
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * True when agent-map answered about no declared path at all.
     *
     * Without this the headline reads "no file outside the declared scope
     * reaches it" for a repository whose map was never built, which is the
     * absent projection reported as a reassuring answer.
     */
    public function nothingProjected(): bool
    {
        return $this->indexedEntryCount === 0;
    }
}
