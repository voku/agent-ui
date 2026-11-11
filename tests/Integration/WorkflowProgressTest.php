<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionProjection;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProgressGateway;
use voku\AgentUi\View\TemplateRenderer;

final class WorkflowProgressTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->root = sys_get_temp_dir() . '/agent_ui_workflow_progress_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
        $this->writeCard('UI-36', 'Workflow progress fixture');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testProgressShowsAndRecordsOnlyTheOwnerProjectedContractApproval(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'UI-36',
            'Show governed workflow progress without moving authority into agent-ui.',
            ['src/Feature/Task'],
            ['no workflow percentage'],
            ['composer ci'],
            'planner',
            acceptanceCriteria: ['Contract approval is recordable from the workflow view.'],
        );

        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $before = $app->handle(new Request('GET', '/task/UI-36/progress'));

        self::assertSame(200, $before->status);
        self::assertStringContainsString('Human decision required', $before->body);
        self::assertStringContainsString('Approve revision 1', $before->body);
        self::assertStringContainsString('data-workflow-brief', $before->body);
        self::assertStringContainsString('Developer briefing', $before->body);
        self::assertStringContainsString('Not approved', $before->body);
        self::assertStringContainsString('What this decision means', $before->body);
        self::assertStringContainsString('href="#workflow-approval"', $before->body);
        self::assertStringContainsString('name="revision" value="1"', $before->body);
        self::assertStringContainsString('Show governed workflow progress without moving authority into agent-ui.', $before->body);
        self::assertStringContainsString('src/Feature/Task', $before->body);
        self::assertStringContainsString('composer ci', $before->body);
        self::assertStringContainsString('no workflow percentage', $before->body);
        self::assertStringContainsString('Contract approval is recordable from the workflow view.', $before->body);
        self::assertStringContainsString('data-workflow-graph', $before->body);
        self::assertStringContainsString('data-workflow-canvas', $before->body);
        self::assertStringContainsString('data-workflow-elements', $before->body);

        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);

        $recorded = $app->handle(new Request(
            'POST',
            '/task/UI-36/approve',
            body: [
                '_csrf' => $csrf,
                'actor' => 'human-reviewer',
                'return_to' => 'progress',
                'revision' => '1',
            ],
        ));

        self::assertSame(303, $recorded->status);
        self::assertSame('/task/UI-36/progress', $recorded->headers['Location'] ?? null);
        $contract = $contracts->find('UI-36');
        self::assertNotNull($contract);
        self::assertSame(TaskContract::APPROVED, $contract->status);
        self::assertSame('human-reviewer', $contract->approvedBy);

        $after = $app->handle(new Request('GET', '/task/UI-36/progress'));
        self::assertStringNotContainsString('Approve Contract</button>', $after->body);
        self::assertStringContainsString('Approved revision 1', $after->body);
        self::assertStringNotContainsString('href="#workflow-approval"', $after->body);
    }

    public function testRefreshReturnsCurrentOwnerProgressAndUpdatedDecision(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'UI-36',
            '<script>alert(1)</script> workflow goal',
            ['src/Feature/Task'],
            [],
            ['composer ci'],
            'planner',
        );
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $app->handle(new Request('GET', '/task/UI-36/progress'));

        $before = $app->handle(new Request('GET', '/task/UI-36/progress.json'));
        self::assertSame(200, $before->status);
        self::assertSame('no-store', $before->headers['Cache-Control'] ?? null);
        self::assertSame('application/json; charset=utf-8', $before->headers['Content-Type'] ?? null);
        $payload = json_decode($before->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(1, $payload['schema_version']);
        self::assertSame('UI-36', $payload['task_id']);
        $owner = (new WorkflowProgressGateway($this->root))->task('UI-36');
        self::assertSame($owner->state, $payload['state']);
        self::assertSame($owner->nextAction, $payload['next_action']);
        self::assertSame($owner->nextActionKind, $payload['next_action_kind']);
        self::assertIsArray($payload['steps']);
        self::assertSame(
            array_map(static fn($step): string => $step->status, $owner->steps),
            array_column($payload['steps'], 'status'),
        );
        self::assertTrue($payload['has_human_decision']);
        self::assertIsString($payload['decision_html']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $payload['decision_html']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $payload['decision_html']);
        self::assertSame(hash('sha256', $payload['decision_html']), $payload['decision_digest']);
        self::assertIsString($payload['brief_html']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $payload['brief_html']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $payload['brief_html']);
        self::assertStringContainsString('Required validation (not a passing result)', $payload['brief_html']);
        self::assertSame(hash('sha256', $payload['brief_html']), $payload['brief_digest']);

        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);
        $approval = $app->handle(new Request('POST', '/task/UI-36/approve', body: [
            '_csrf' => $csrf,
            'actor' => 'human-reviewer',
            'return_to' => 'progress',
            'revision' => '1',
        ]));
        self::assertSame(303, $approval->status);

        $after = $app->handle(new Request('GET', '/task/UI-36/progress.json'));
        self::assertSame(200, $after->status);
        $updated = json_decode($after->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($updated);
        $current = (new WorkflowProgressGateway($this->root))->task('UI-36');
        self::assertSame($current->nextAction, $updated['next_action']);
        self::assertNotSame($payload['decision_digest'], $updated['decision_digest']);
        self::assertNotSame($payload['brief_digest'], $updated['brief_digest']);
        self::assertIsString($updated['brief_html']);
        self::assertStringContainsString('Approved revision 1', $updated['brief_html']);
        self::assertIsString($updated['decision_html']);
        self::assertStringNotContainsString('Approve Contract</button>', $updated['decision_html']);
    }

    public function testExactOwnerReviewAndLearningDecisionsRenderAsHtmlForms(): void
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $card = (new BoardProjectionGateway($this->root))->card('UI-36');
        $digest = str_repeat('a', 64);
        $review = $templates->render('task/_progress-decision', [
            'card' => $card,
            'human_decisions' => new WorkflowHumanDecisionProjection(
                'UI-36',
                [WorkflowHumanDecisionProjection::ACKNOWLEDGE_REVIEW],
                $digest,
            ),
            'contract' => null,
            'csrf_token' => 'test-csrf',
        ]);

        self::assertStringContainsString('Review the exact report before acknowledging', $review);
        self::assertStringContainsString('action="/task/UI-36/review-ack"', $review);
        self::assertStringContainsString('name="report_sha256" value="' . $digest . '"', $review);
        self::assertStringContainsString('name="return_to" value="progress"', $review);
        self::assertStringContainsString('data-workflow-decision-form', $review);

        $learning = $templates->render('task/_progress-decision', [
            'card' => $card,
            'human_decisions' => new WorkflowHumanDecisionProjection(
                'UI-36',
                [WorkflowHumanDecisionProjection::RECORD_LEARNING],
                null,
            ),
            'contract' => null,
            'csrf_token' => 'test-csrf',
        ]);

        self::assertStringContainsString('Learning disposition', $learning);
        self::assertSame(2, substr_count($learning, 'action="/task/UI-36/learning"'));
        self::assertStringContainsString('name="decision" value="no_durable_learning"', $learning);
        self::assertStringContainsString('name="decision" value="follow_up_required"', $learning);
        self::assertStringContainsString('name="follow_up_ref"', $learning);
        self::assertStringNotContainsString('action="/task/UI-36/approve"', $learning);
    }

    public function testRefreshOfUnknownTaskIsAnUncacheableJsonError(): void
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $response = $app->handle(new Request('GET', '/task/UI-999/progress.json'));

        self::assertSame(404, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame(['error' => 'Task not found.'], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRefreshReportsAnUnavailableOwnerProjection(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-36', 'A task.', ['src'], [], ['composer ci'], 'planner');
        file_put_contents($contracts->path('UI-36'), '{invalid json');

        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $response = $app->handle(new Request('GET', '/task/UI-36/progress.json'));

        self::assertSame(503, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        self::assertSame(['error' => 'Workflow projection unavailable.'], json_decode($response->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRefreshEndpointDoesNotAcceptMutations(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-36', 'A task.', ['src'], [], ['composer ci'], 'planner');
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');

        $response = $app->handle(new Request('POST', '/task/UI-36/progress.json', body: ['actor' => 'unexpected']));

        self::assertSame(400, $response->status);
        self::assertSame(TaskContract::CANDIDATE, $contracts->find('UI-36')?->status);
    }

    public function testUnknownDecisionReturnTargetFailsBeforeRecordingApproval(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-36', 'Bounded redirect.', ['src'], [], ['composer ci'], 'planner');

        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $app->handle(new Request('GET', '/task/UI-36/progress'));
        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);

        $response = $app->handle(new Request(
            'POST',
            '/task/UI-36/approve',
            body: [
                '_csrf' => $csrf,
                'actor' => 'human-reviewer',
                'return_to' => 'https://example.invalid/',
            ],
        ));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Unsupported human-decision return target.', $response->body);
        $contract = $contracts->find('UI-36');
        self::assertNotNull($contract);
        self::assertSame(TaskContract::CANDIDATE, $contract->status);
        self::assertNull($contract->approvedBy);
    }

    public function testApprovalRejectsAStaleRevisionSubmittedByTheForm(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-36', 'Original goal.', ['src'], [], ['composer ci'], 'planner');
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $app->handle(new Request('GET', '/task/UI-36/progress'));
        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);

        $contracts->revise('UI-36', 'Updated goal.', ['src'], [], ['composer ci'], 'planner');
        $response = $app->handle(new Request(
            'POST',
            '/task/UI-36/approve',
            body: [
                '_csrf' => $csrf,
                'actor' => 'human-reviewer',
                'return_to' => 'progress',
                'revision' => '1',
            ],
        ));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Contract revision changed since it was reviewed (reviewed 1, current 2)', $response->body);
        $contract = $contracts->find('UI-36');
        self::assertNotNull($contract);
        self::assertSame(2, $contract->revision);
        self::assertSame(TaskContract::CANDIDATE, $contract->status);
        self::assertNull($contract->approvedBy);
    }

    public function testApprovalRequiresRevisionBeforeCallingTheOwner(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-36', 'Original goal.', ['src'], [], ['composer ci'], 'planner');
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $app->handle(new Request('GET', '/task/UI-36/progress'));
        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);

        foreach ([null, '', '0', '-1', 'not-a-revision', str_repeat('9', 40)] as $revision) {
            $body = [
                '_csrf' => $csrf,
                'actor' => 'human-reviewer',
                'return_to' => 'progress',
            ];
            if ($revision !== null) {
                $body['revision'] = $revision;
            }

            $response = $app->handle(new Request('POST', '/task/UI-36/approve', body: $body));
            self::assertSame(400, $response->status);
            self::assertStringContainsString('A positive Contract revision is required', $response->body);
        }

        $contract = $contracts->find('UI-36');
        self::assertNotNull($contract);
        self::assertSame(1, $contract->revision);
        self::assertSame(TaskContract::CANDIDATE, $contract->status);
        self::assertNull($contract->approvedBy);
    }

    public function testMoveRedirectRefusesProtocolRelativeReturnTarget(): void
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $app->handle(new Request('GET', '/task/UI-36/progress'));
        $csrf = $_SESSION['_agent_ui_csrf'] ?? null;
        self::assertIsString($csrf);

        $response = $app->handle(new Request(
            'POST',
            '/task/UI-36/move',
            body: [
                '_csrf' => $csrf,
                'target_lane' => 'READY',
                'return_to' => '//attacker.example/path',
            ],
        ));

        self::assertSame(303, $response->status);
        self::assertSame('/task/UI-36', $response->headers['Location'] ?? null);
    }

    private function writeCard(string $id, string $title): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/' . $id . '.md',
            '# ' . $id . ': ' . $title . "\n\n"
            . "- **Lane:** BACKLOG\n"
            . "- **Status:** todo\n\n"
            . "## Summary\n\n" . $title . "\n\n"
            . "## Agent Task Brief\n\nA brief required for the READY lane.\n",
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
