<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\AttentionKind;
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
 * Human Attention Resolution workflow integration tests.
 *
 * Verifies that when an execution stage enters an Attention state in agent-loop,
 * agent-ui surfaces the decision context, consequences, and authority requirements,
 * submits through typed owner operations, increments the attempt, clears attention,
 * and safely fails closed on invalid, unauthorized, or stale resolution submissions.
 */
final class AttentionResolutionDecisionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-attention-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o775, true) || !mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create the fixture root.');
        }
        copy(dirname(__DIR__, 2) . '/composer.json', $this->root . '/composer.json');
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
        file_put_contents($this->root . '/src/Worker.php', "<?php\nfinal class Worker { public function run(): string { return 'idle'; } }\n");
        $this->git('init -q . && git config user.email t@example.test && git config user.name t && git add -A && git commit -q -m base');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTaskOverviewAndSubviewsSurfaceAttentionWhenStageRequiresClarification(): void
    {
        $app = $this->createTaskWithPendingAttention(
            taskId: 'APP-1',
            outcome: StageOutcome::NEEDS_CLARIFICATION,
            message: 'Ambiguity in business requirement for worker output.',
        );

        $gateway = new HumanDecisionGateway($this->root);
        $attention = $gateway->pendingAttention('APP-1');
        self::assertNotNull($attention, 'Precondition: Execution must hold pending Attention.');
        self::assertSame(AttentionKind::CLARIFICATION_REQUIRED, $attention->kind);
        self::assertSame('Ambiguity in business requirement for worker output.', $attention->message);

        // 1. Task overview page renders Attention in #decision
        $overview = $app->handle(new Request('GET', '/task/APP-1'));
        self::assertSame(200, $overview->status);
        $overviewMain = $this->main($overview->body);
        self::assertStringContainsString('id="decision"', $overviewMain);
        self::assertStringContainsString('Execution stage investigate requires human attention', $overviewMain);
        self::assertStringContainsString('Ambiguity in business requirement for worker output.', $overviewMain);
        self::assertStringContainsString((string) $attention->id, $overviewMain);
        self::assertStringContainsString('action="/task/APP-1/attention"', $overviewMain);
        self::assertStringContainsString('name="actor"', $overviewMain);

        // 2. Persistent header links to #decision across all task subviews
        foreach (['', '/progress', '/contract', '/context', '/work', '/evidence', '/history', '/prompts', '/learning', '/edit'] as $view) {
            $response = $app->handle(new Request('GET', '/task/APP-1' . $view));
            self::assertSame(200, $response->status, '/task/APP-1' . $view);
            self::assertStringContainsString('href="/task/APP-1#decision"', $response->body, $view . ' must lead to #decision');
            self::assertStringContainsString('Go to the decision agent-loop is waiting for', $response->body);
        }

        // 3. Workflow progress page renders the decision cockpit
        $progress = $app->handle(new Request('GET', '/task/APP-1/progress'));
        self::assertSame(200, $progress->status);
        self::assertStringContainsString('Attention required: stage investigate', $progress->body);
        self::assertStringContainsString('data-workflow-decision-form', $progress->body);
        self::assertStringContainsString('Resolve Attention', $progress->body);

        // 4. Progress json refresh includes human decision indicator and decision HTML
        $json = $app->handle(new Request('GET', '/task/APP-1/progress.json'));
        self::assertSame(200, $json->status);
        $data = json_decode($json->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertTrue($data['has_human_decision'] ?? false);
        self::assertIsString($data['decision_html'] ?? null);
        self::assertStringContainsString((string) $attention->id, $data['decision_html']);
    }

    public function testAuthorizedAttentionResolutionRecordsDecisionAndResumesAttempt(): void
    {
        $app = $this->createTaskWithPendingAttention(
            taskId: 'APP-1',
            outcome: StageOutcome::NEEDS_CLARIFICATION,
            message: 'Need human approval to proceed with build.',
        );

        $gateway = new HumanDecisionGateway($this->root);
        $attention = $gateway->pendingAttention('APP-1');
        self::assertNotNull($attention);

        $execGateway = new ExecutionGateway($this->root);
        $initialAttempt = $execGateway->projection('APP-1')->currentAttempt;
        self::assertSame(1, $initialAttempt);

        $csrf = (new CsrfTokenManager())->token();
        $response = $app->handle(new Request('POST', '/task/APP-1/attention', body: [
            '_csrf' => $csrf,
            'attention_id' => $attention->id,
            'actor' => 'operator-alice',
            'return_to' => 'progress',
        ]));

        self::assertSame(303, $response->status);
        self::assertSame('/task/APP-1/progress', $response->headers['Location'] ?? '');

        // Canonical owner verification: Attention cleared and attempt incremented
        $projection = $execGateway->projection('APP-1');
        self::assertNull($projection->attention, 'Attention must be cleared in owner execution state.');
        self::assertSame(2, $projection->currentAttempt, 'Execution attempt must be incremented.');
        self::assertNull($gateway->pendingAttention('APP-1'));

        // Subsequent page view: No attention panel or decision link
        $freshProgress = $app->handle(new Request('GET', '/task/APP-1/progress'));
        self::assertSame(200, $freshProgress->status);
        self::assertStringNotContainsString('Attention required: stage investigate', $freshProgress->body);
        self::assertStringNotContainsString('href="/task/APP-1#decision"', $freshProgress->body);

        $freshJson = $app->handle(new Request('GET', '/task/APP-1/progress.json'));
        $data = json_decode($freshJson->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertFalse($data['has_human_decision'] ?? true);
    }

    public function testResolutionFailsClosedOnMissingOrInvalidCsrf(): void
    {
        $app = $this->createTaskWithPendingAttention('APP-1', StageOutcome::NEEDS_CLARIFICATION, 'Clarification needed');
        $attention = (new HumanDecisionGateway($this->root))->pendingAttention('APP-1');
        self::assertNotNull($attention);

        $response = $app->handle(new Request('POST', '/task/APP-1/attention', body: [
            '_csrf' => 'invalid-token',
            'attention_id' => $attention->id,
            'actor' => 'operator',
        ]));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Invalid CSRF token.', $response->body);
    }

    public function testResolutionFailsClosedOnMissingActor(): void
    {
        $app = $this->createTaskWithPendingAttention('APP-1', StageOutcome::NEEDS_CLARIFICATION, 'Clarification needed');
        $attention = (new HumanDecisionGateway($this->root))->pendingAttention('APP-1');
        self::assertNotNull($attention);

        $response = $app->handle(new Request('POST', '/task/APP-1/attention', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'attention_id' => $attention->id,
            'actor' => '   ',
        ]));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('actor must be a non-empty value', $response->body);
    }

    public function testResolutionFailsClosedOnMismatchedAttentionId(): void
    {
        $app = $this->createTaskWithPendingAttention('APP-1', StageOutcome::NEEDS_CLARIFICATION, 'Clarification needed');

        $response = $app->handle(new Request('POST', '/task/APP-1/attention', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'attention_id' => 'attention:non-existent-id',
            'actor' => 'operator',
        ]));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('No matching pending Attention exists for this execution.', $response->body);
    }

    public function testResolutionFailsClosedOnStaleResolution(): void
    {
        $app = $this->createTaskWithPendingAttention('APP-1', StageOutcome::NEEDS_CLARIFICATION, 'Clarification needed');
        $attention = (new HumanDecisionGateway($this->root))->pendingAttention('APP-1');
        self::assertNotNull($attention);

        $csrf = (new CsrfTokenManager())->token();

        // First resolution succeeds
        $first = $app->handle(new Request('POST', '/task/APP-1/attention', body: [
            '_csrf' => $csrf,
            'attention_id' => $attention->id,
            'actor' => 'operator',
        ]));
        self::assertSame(303, $first->status);

        // Stale retry with same attention ID is rejected
        $stale = $app->handle(new Request('POST', '/task/APP-1/attention', body: [
            '_csrf' => $csrf,
            'attention_id' => $attention->id,
            'actor' => 'operator',
        ]));
        self::assertSame(400, $stale->status);
        self::assertStringContainsString('No matching pending Attention exists for this execution.', $stale->body);
    }

    private function createTaskWithPendingAttention(string $taskId, StageOutcome $outcome, string $message): Application
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => $taskId,
            'title' => 'Worker execution task',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Execute worker under governance',
            'task_brief' => 'Execute Worker::run',
            'validation' => 'php -l src/Worker.php',
            'priority' => '2',
            'assignee' => 'dev',
        ]));
        self::assertSame(303, $created->status);

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            taskId: $taskId,
            goal: 'Execute worker correctly.',
            scope: ['src/Worker.php'],
            nonGoals: ['no api changes'],
            validation: ['php -l src/Worker.php'],
            plannedBy: 'planner',
            baseCommit: trim($this->git('rev-parse HEAD')),
        );
        $contract = $contracts->load($taskId);
        $contracts->approve($taskId, 'maintainer', $contract->revision);

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

    private function main(string $body): string
    {
        $start = strpos($body, '<main');
        $end = strpos($body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($body, $start, $end - $start);
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
