<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLearning;

use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\GuidanceProjection;
use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentLearning\Catalog\TaskLearningProjection;
use voku\AgentLearning\LearningCatalog;
use voku\AgentLoop\ProjectLayout;

/**
 * Thin UI integration over Learning's typed, read-only catalog.
 *
 * The Learning root is resolved by agent-loop's ProjectLayout owner. The UI
 * never discovers Learning state by scanning directories or parsing records.
 */
final readonly class LearningCatalogGateway
{
    private LearningCatalog $catalog;

    public function __construct(private string $projectRoot)
    {
        $this->catalog = new LearningCatalog((new ProjectLayout($projectRoot))->learningRoot());
    }

    public function overview(): LearningOverview
    {
        return $this->catalog->overview();
    }

    /**
     * @return list<FindingProjection>
     */
    public function findings(?string $status = null): array
    {
        return $this->catalog->findings($status);
    }

    /**
     * @return list<ProposalProjection>
     */
    public function proposals(?string $status = null): array
    {
        return $this->catalog->proposals($status);
    }

    /**
     * @return list<array{subject: string, rule: string, canonicalHome: string}>
     */
    public function memoryRules(): array
    {
        return MemoryReader::parse($this->projectRoot)['rules'];
    }

    /**
     * @return list<array{archivedOn: string, task: string, summary: string, reason: string, candidate: string, promotedTo: string}>
     */
    public function archivedTasks(): array
    {
        return MemoryReader::parse($this->projectRoot)['archivedTasks'];
    }

    public function finding(string $findingId): ?FindingProjection
    {
        return $this->catalog->finding($findingId);
    }

    public function proposal(string $proposalId): ?ProposalProjection
    {
        return $this->catalog->proposal($proposalId);
    }

    public function guidance(string $guidanceId): ?GuidanceProjection
    {
        return $this->catalog->guidance($guidanceId);
    }

    public function task(string $taskId): TaskLearningProjection
    {
        return $this->catalog->forTask($taskId);
    }
}
