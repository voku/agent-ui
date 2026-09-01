<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Knowledge;

use InvalidArgumentException;
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class KnowledgeAction
{
    public function __construct(
        private LearningCatalogGateway $learning,
        private TemplateRenderer $templates,
    ) {
    }

    public function overview(?Request $request = null): Response
    {
        $overview = $this->learning->overview();
        $tab = $request?->query['tab'] ?? 'overview';
        $status = $request?->query['status'] ?? null;

        $findings = [];
        foreach ($overview->recentFindingIds as $findingId) {
            $finding = $this->learning->finding($findingId);
            if ($finding instanceof FindingProjection) {
                $findings[] = $finding;
            }
        }
        $proposals = [];
        foreach ($overview->recentProposalIds as $proposalId) {
            $proposal = $this->learning->proposal($proposalId);
            if ($proposal instanceof ProposalProjection) {
                $proposals[] = $proposal;
            }
        }

        $allFindings = $this->learning->findings($status);
        $allProposals = $this->learning->proposals($status);
        $memoryRules = $this->learning->memoryRules();
        $archivedTasks = $this->learning->archivedTasks();

        return Response::html($this->templates->render('knowledge/index', [
            'overview' => $overview,
            'recent_findings' => $findings,
            'recent_proposals' => $proposals,
            'all_findings' => $allFindings,
            'all_proposals' => $allProposals,
            'memory_rules' => $memoryRules,
            'archived_tasks' => $archivedTasks,
            'current_tab' => $tab,
            'current_status' => $status,
        ]));
    }

    public function finding(string $findingId): Response
    {
        $finding = $this->learning->finding($findingId);
        if ($finding === null) {
            throw new InvalidArgumentException('Learning finding not found.');
        }

        return Response::html($this->templates->render('knowledge/finding', [
            'finding' => $finding,
        ]));
    }

    public function proposal(string $proposalId): Response
    {
        $proposal = $this->learning->proposal($proposalId);
        if ($proposal === null) {
            throw new InvalidArgumentException('Learning proposal not found.');
        }

        return Response::html($this->templates->render('knowledge/proposal', [
            'proposal' => $proposal,
        ]));
    }

    public function guidance(string $guidanceId): Response
    {
        $guidance = $this->learning->guidance($guidanceId);
        if ($guidance === null) {
            throw new InvalidArgumentException('Durable guidance not found.');
        }

        return Response::html($this->templates->render('knowledge/guidance', [
            'guidance' => $guidance,
        ]));
    }

    public function task(string $taskId): Response
    {
        return Response::html($this->templates->render('knowledge/task', [
            'learning' => $this->learning->task($taskId),
        ]));
    }
}
