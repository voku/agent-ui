<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use InvalidArgumentException;
use Throwable;
use voku\AgentLoop\Execution\AttentionRequest;
use voku\AgentLoop\Execution\AttentionResolution;
use voku\AgentLoop\Execution\AttentionResolutionStore;
use voku\AgentLoop\Execution\ExecutionArtifactValue;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowContractApprovalPresentation;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjector;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;

final readonly class HumanDecisionGateway
{
    private WorkflowHumanDecisionService $service;
    private TaskContractStore $contractStore;
    private WorkflowHumanDecisionProjector $approvalProjector;

    public function __construct(private string $projectRoot)
    {
        $this->service = new WorkflowHumanDecisionService($projectRoot);
        $this->contractStore = new TaskContractStore($projectRoot);
        $this->approvalProjector = new WorkflowHumanDecisionProjector($projectRoot);
    }

    public function contract(string $taskId): ?TaskContract
    {
        return $this->contractStore->find($taskId);
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $acceptanceCriteria
     */
    public function proposeContract(
        string $taskId,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        string $plannedBy,
        array $acceptanceCriteria = [],
    ): TaskContract {
        return $this->contractStore->create(
            taskId: $taskId,
            goal: $goal,
            scope: $scope,
            nonGoals: $nonGoals,
            validation: $validation,
            plannedBy: $plannedBy,
            acceptanceCriteria: $acceptanceCriteria,
        );
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $acceptanceCriteria
     */
    public function reviseContract(
        string $taskId,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        string $plannedBy,
        array $acceptanceCriteria = [],
    ): TaskContract {
        return $this->contractStore->revise(
            taskId: $taskId,
            goal: $goal,
            scope: $scope,
            nonGoals: $nonGoals,
            validation: $validation,
            plannedBy: $plannedBy,
            acceptanceCriteria: $acceptanceCriteria,
        );
    }

    /**
     * The archived Contract revisions this task's current revision replaced.
     *
     * agent-loop owns the archive and its ordering; the UI never reads the
     * history directory itself.
     *
     * @return list<TaskContract> oldest first
     */
    public function supersededRevisions(string $taskId): array
    {
        return $this->contractStore->supersededRevisions($taskId);
    }

    public function contractApprovalPresentation(
        string $taskId,
        string $nextAction,
        string $nextActionKind,
    ): ?WorkflowContractApprovalPresentation {
        return $this->approvalProjector->projectContractApprovalPresentation($taskId, $nextAction, $nextActionKind);
    }

    public function available(string $taskId): WorkflowHumanDecisionProjection
    {
        return $this->service->availableActions($taskId);
    }

    public function approve(string $taskId, string $actor, int $expectedRevision): void
    {
        if ($expectedRevision < 1) {
            throw new \InvalidArgumentException('A positive expected Contract revision is required.');
        }

        $contract = $this->contractStore->find($taskId);
        if ($contract === null || $contract->revision !== $expectedRevision) {
            throw new \InvalidArgumentException('Contract revision changed; reload the page before approving.');
        }

        $approveContract = new \ReflectionMethod($this->service, 'approveContract');
        $arguments = [$taskId, $actor];
        if ($approveContract->getNumberOfParameters() >= 3) {
            $arguments[] = $expectedRevision;
        }

        // Older installed agent-loop releases accept only task and actor. The
        // check above keeps those releases safe for stale form submissions;
        // newer releases receive the revision for the owner's atomic check.
        $approveContract->invokeArgs($this->service, $arguments);
    }

    public function acknowledgeReview(string $taskId, string $reportSha256, string $actor): void
    {
        $this->service->acknowledgeReview($taskId, $reportSha256, $actor);
    }

    public function recordLearning(
        string $taskId,
        string $decision,
        string $actor,
        string $reason,
        ?string $followUpRef,
    ): void {
        $this->service->recordLearning($taskId, $decision, $actor, $reason, followUpRef: $followUpRef);
    }

    public function pendingAttention(string $taskId): ?AttentionRequest
    {
        try {
            $projection = (new ExecutionGateway($this->projectRoot))->projection($taskId);

            return $projection->attention;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<AttentionResolution> */
    public function attentionResolutions(string $taskId): array
    {
        try {
            $dir = (new ProjectLayout($this->projectRoot))->executionAttentionResolutionsRoot($taskId);
        } catch (Throwable) {
            return [];
        }
        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        $resolutions = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            $json = @file_get_contents($path);
            if (!is_string($json)) {
                continue;
            }
            try {
                $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'execution_attention_resolution') {
                continue;
            }
            $revision = $data['contract_revision'] ?? null;
            $attempt = $data['attempt'] ?? null;
            if (!is_int($revision) || $revision < 1 || !is_int($attempt) || $attempt < 1) {
                continue;
            }

            try {
                $resolutions[] = new AttentionResolution(
                    ExecutionArtifactValue::string($data['attention_id'] ?? null, $path . '#attention_id'),
                    ExecutionArtifactValue::string($data['task_id'] ?? null, $path . '#task_id'),
                    ExecutionArtifactValue::string($data['run_id'] ?? null, $path . '#run_id'),
                    $revision,
                    ExecutionArtifactValue::sha256($data['execution_plan_digest'] ?? null, $path . '#execution_plan_digest'),
                    ExecutionArtifactValue::string($data['stage_id'] ?? null, $path . '#stage_id'),
                    $attempt,
                    ExecutionArtifactValue::string($data['actor'] ?? null, $path . '#actor'),
                    ExecutionArtifactValue::string($data['resolved_at'] ?? null, $path . '#resolved_at'),
                );
            } catch (Throwable) {
                continue;
            }
        }

        usort(
            $resolutions,
            static fn(AttentionResolution $left, AttentionResolution $right): int => strcmp($left->resolvedAt, $right->resolvedAt),
        );

        return $resolutions;
    }

    public function resolveAttention(string $taskId, string $attentionId, string $actor): void
    {
        $attentionId = trim($attentionId);
        if ($attentionId === '') {
            throw new InvalidArgumentException('A non-empty attention ID is required.');
        }

        $actor = trim($actor);
        if ($actor === '') {
            throw new InvalidArgumentException('A non-empty actor is required to resolve Attention.');
        }

        $gateway = new ExecutionGateway($this->projectRoot);
        try {
            $projection = $gateway->projection($taskId);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('No execution exists for task ' . $taskId . '.', previous: $exception);
        }

        $attention = $projection->attention;
        if ($attention === null || $attention->id !== $attentionId) {
            throw new InvalidArgumentException('No matching pending Attention exists for this execution.');
        }

        $plan = (new ExecutionPlanStore($this->projectRoot))->load($taskId);
        try {
            (new AttentionResolutionStore($this->projectRoot))->record(
                $plan,
                $attention,
                $projection->currentAttempt,
                $actor,
            );
            $gateway->resolveAttention($taskId, $attentionId);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Attention resolution failed: ' . $exception->getMessage(), previous: $exception);
        }
    }
}
