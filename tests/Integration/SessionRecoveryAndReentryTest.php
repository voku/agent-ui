<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * P2: Session recovery and re-entry integration tests.
 *
 * Proves that after an attention interruption or session stop:
 * 1. The history timeline records both the attention requirement and the authoritative human resolution.
 * 2. The workflow progress cockpit surfaces execution continuation controls (resumption via runner and prompt handoff).
 * 3. Governed handoff redirects and preselects the continue-until-done recipe with pre-populated acceptance criteria.
 */
final class SessionRecoveryAndReentryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-recovery-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o775, true) || !mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create the fixture root.');
        }
        copy(dirname(__DIR__, 2) . '/composer.json', $this->root . '/composer.json');
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** REC\n");
        file_put_contents($this->root . '/src/Processor.php', "<?php\nfinal class Processor { public function process(): bool { return true; } }\n");
        $this->git('init -q . && git config user.email dev@example.invalid && git config user.name Developer && git add -A && git commit -q -m base');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAttentionRequirementsAndResolutionsAppearInTaskHistory(): void
    {
        $app = $this->createTaskWithPendingAttention(
            taskId: 'REC-1',
            outcome: StageOutcome::NEEDS_CLARIFICATION,
            message: 'Clarify processor return type requirement.',
        );

        $gateway = new HumanDecisionGateway($this->root);
        $attention = $gateway->pendingAttention('REC-1');
        self::assertNotNull($attention);

        // Before resolution: History shows attention required
        $historyBefore = $app->handle(new Request('GET', '/task/REC-1/history'));
        self::assertSame(200, $historyBefore->status);
        self::assertStringContainsString('Attention required: stage', $historyBefore->body);
        self::assertStringContainsString('Clarify processor return type requirement.', $historyBefore->body);
        self::assertStringContainsString($attention->id, $historyBefore->body);

        // Resolve attention in the browser
        $csrf = (new CsrfTokenManager())->token();
        $resolveResponse = $app->handle(new Request(
            method: 'POST',
            path: '/task/REC-1/attention',
            body: [
                '_csrf' => $csrf,
                'attention_id' => $attention->id,
                'actor' => 'sarah-reviewer',
                'return_to' => 'progress',
            ] + $this->reviewed('REC-1'),
        ));
        self::assertSame(303, $resolveResponse->status);
        self::assertSame('/task/REC-1/progress', $resolveResponse->headers['Location']);

        // After resolution: Attention resolution store holds the record
        $resolutions = $gateway->attentionResolutions('REC-1');
        self::assertCount(1, $resolutions);
        self::assertSame($attention->id, $resolutions[0]->attentionId);
        self::assertSame('sarah-reviewer', $resolutions[0]->actor);
        self::assertSame($attention->stageId ?? 'investigate', $resolutions[0]->stageId);
        self::assertSame(1, $resolutions[0]->attempt);

        // History shows both the requirement and the resolution with timestamps and actors
        $historyAfter = $app->handle(new Request('GET', '/task/REC-1/history'));
        self::assertSame(200, $historyAfter->status);
        self::assertStringContainsString('Attention resolved: stage', $historyAfter->body);
        self::assertStringContainsString('sarah-reviewer', $historyAfter->body);
        self::assertStringContainsString('Attempt 1 resolved by sarah-reviewer', $historyAfter->body);
    }

    public function testWorkflowProgressSurfacesResumptionControlsAfterAttentionResolution(): void
    {
        $app = $this->createTaskWithPendingAttention(
            taskId: 'REC-2',
            outcome: StageOutcome::NEEDS_CLARIFICATION,
            message: 'Clarification required before build continuation.',
        );

        $gateway = new HumanDecisionGateway($this->root);
        $attention = $gateway->pendingAttention('REC-2');
        self::assertNotNull($attention);

        // Resolve attention
        $csrf = (new CsrfTokenManager())->token();
        $app->handle(new Request(
            method: 'POST',
            path: '/task/REC-2/attention',
            body: [
                '_csrf' => $csrf,
                'attention_id' => $attention->id,
                'actor' => 'maintainer-dan',
                'return_to' => 'progress',
            ] + $this->reviewed('REC-2'),
        ));

        // Now inspect Workflow Progress cockpit
        $progress = $app->handle(new Request('GET', '/task/REC-2/progress'));
        self::assertSame(200, $progress->status);

        // The execution continuation panel is visible
        self::assertStringContainsString('Execution continuation ready', $progress->body);
        self::assertStringContainsString('attempt 2', $progress->body);
        self::assertStringContainsString('Resume execution', $progress->body);
        self::assertStringContainsString('Continue via Prompt Workbench →', $progress->body);
        self::assertStringContainsString('/task/REC-2/prompts?recipe=continue-until-done', $progress->body);
    }

    public function testGovernedHandoffRedirectsAndPreselectsContinueUntilDone(): void
    {
        $app = $this->createTaskWithPendingAttention(
            taskId: 'REC-3',
            outcome: StageOutcome::NEEDS_CLARIFICATION,
            message: 'Awaiting human clarification.',
        );

        // Resolve attention
        $gateway = new HumanDecisionGateway($this->root);
        $attention = $gateway->pendingAttention('REC-3');
        self::assertNotNull($attention);

        $csrf = (new CsrfTokenManager())->token();
        $app->handle(new Request(
            method: 'POST',
            path: '/task/REC-3/attention',
            body: [
                '_csrf' => $csrf,
                'attention_id' => $attention->id,
                'actor' => 'maintainer-dan',
            ] + $this->reviewed('REC-3'),
        ));

        // Handoff route redirects to prompts with continue-until-done recipe query
        $handoffResponse = $app->handle(new Request('GET', '/task/REC-3/handoff'));
        self::assertSame(303, $handoffResponse->status);
        self::assertSame('/task/REC-3/prompts?recipe=continue-until-done', $handoffResponse->headers['Location']);

        // Visiting prompts view for this task with continue-until-done preselects it and defaults done_condition
        $promptsResponse = $app->handle(new Request(
            method: 'GET',
            path: '/task/REC-3/prompts',
            query: ['recipe' => 'continue-until-done'],
        ));
        self::assertSame(200, $promptsResponse->status);
        self::assertStringContainsString('value="continue-until-done" checked', $promptsResponse->body);
        self::assertStringContainsString('Continue until done', $promptsResponse->body);
        self::assertStringContainsString('name="arg_done_condition"', $promptsResponse->body);
        self::assertStringContainsString('All approved acceptance criteria and validation pass without errors.', $promptsResponse->body);
    }

    /** @return array<string, string> the owner identity the workspace form carries */
    private function reviewed(string $taskId): array
    {
        $decision = (new HumanDecisionGateway($this->root))->attentionDecision($taskId);
        self::assertNotNull($decision);

        return [
            'revision' => (string) $decision->contractRevision,
            'execution_plan_digest' => $decision->executionPlanDigest,
            'attempt' => (string) $decision->attempt,
            'candidate_revision' => $decision->candidateRevision,
            'head_commit' => $decision->headCommit ?? '',
        ];
    }

    private function createTaskWithPendingAttention(
        string $taskId,
        StageOutcome $outcome,
        string $message,
    ): Application {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => $taskId,
            'title' => 'Processor pipeline task',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Execute processor under governance',
            'task_brief' => 'Execute Processor::process',
            'validation' => 'php -l src/Processor.php',
            'priority' => '1',
            'assignee' => 'dev',
        ]));
        self::assertSame(303, $created->status);

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            taskId: $taskId,
            goal: 'Implement processor pipeline with attention and re-entry recovery support.',
            scope: ['src/Processor.php'],
            nonGoals: ['do not touch composer.json'],
            validation: ['php -l src/Processor.php'],
            plannedBy: 'planner-alice',
            baseCommit: trim($this->git('rev-parse HEAD')),
            acceptanceCriteria: ['Processor pipeline passes validation and resumes cleanly.'],
        );
        $contract = $contracts->load($taskId);
        $contracts->approve($taskId, 'maintainer-bob', $contract->revision);

        self::assertSame(0, $this->frontDoor('enter', $taskId));

        $gateway = new ExecutionGateway($this->root);
        $projection = $gateway->projection($taskId);
        $stageId = $projection->currentStageId ?? 'investigate';
        $bundle = $gateway->prepareStage($taskId, $stageId);

        $result = new StageResult(
            submissionId: 'sub:' . bin2hex(random_bytes(6)),
            taskId: $taskId,
            runId: $bundle->runId,
            contractRevision: $bundle->contractRevision,
            executionPlanDigest: $bundle->executionPlanDigest,
            stageId: $stageId,
            attempt: 1,
            outcome: $outcome,
            candidateRevision: $bundle->candidateRevision,
            artifactReferences: [],
            validationReferences: [],
            summary: $message,
        );
        $gateway->submitStageResult($result);

        return $app;
    }

    private function frontDoor(string $command, string $taskId): int
    {
        ob_start();
        try {
            return (new HostFrontDoorApplication($this->root))->run($command, [$taskId, '--format=json']);
        } finally {
            ob_end_clean();
        }
    }

    private function git(string $arguments): string
    {
        $output = shell_exec('cd ' . escapeshellarg($this->root) . ' && git ' . $arguments . ' 2>&1');

        return is_string($output) ? $output : '';
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) && !is_link($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
