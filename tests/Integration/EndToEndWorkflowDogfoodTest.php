<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentSession\SessionStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * End-to-end dogfood workflow test proving the full developer cycle:
 * Task 1 creation -> Contract proposal -> Human contract approval -> Governed enter ->
 * Stage interrupted by Attention requirement ->
 * Human attention resolution in browser -> Continuation -> Verification ->
 * Review report findings -> Human review acknowledgement in browser ->
 * Learning finding & note published ->
 * Task 2 creation -> Contract approval -> Enter -> Recall compiles and delivers Task 1 precedent ->
 * Full bidirectional Learning -> Recall lineage verified across both tasks and knowledge views.
 */
final class EndToEndWorkflowDogfoodTest extends TestCase
{
    private const string PRECEDENT_TITLE = 'Prefer typed owner operations over simulated browser mutations';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-dogfood-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o775, true) || !mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
        copy(dirname(__DIR__, 2) . '/composer.json', $this->root . '/composer.json');
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** DOGFOOD\n");
        file_put_contents($this->root . '/src/Greeter.php', "<?php\nfinal class Greeter { public function greet(string \$name): string { return 'Hello ' . \$name; } }\n");
        $this->git('init -q . && git config user.email dev@example.invalid && git config user.name Developer && git add -A && git commit -q -m base');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCompleteDogfoodCycleFromCreationThroughAttentionVerificationAndLineage(): void
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $csrf = (new CsrfTokenManager())->token();

        // =========================================================================
        // Step 1: Task 1 Creation on Kanban Board
        // =========================================================================
        $card1Response = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => $csrf,
            'card_id' => 'DOGFOOD-1',
            'title' => 'Implement Greeter Service',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Build and verify Greeter service with robust authority checks',
            'task_brief' => 'Detailed brief for Greeter.',
            'validation' => 'php -l src/Greeter.php',
            'priority' => '1',
            'assignee' => 'alice',
        ]));
        self::assertSame(303, $card1Response->status);

        // =========================================================================
        // Step 2: Contract Proposal & Human Approval Boundary
        // =========================================================================
        $contract1Response = $app->handle(new Request('POST', '/task/DOGFOOD-1/contract', body: [
            '_csrf' => $csrf,
            'goal' => 'Implement and verify the Greeter service.',
            'scope' => "src/Greeter.php\ncomposer.json",
            'non_goals' => "no network dependencies\nno new framework",
            'validation' => 'php -l src/Greeter.php',
            'planned_by' => 'planner-agent',
            'acceptance_criteria' => "greet() returns valid greeting\nall validation passes",
            'tags' => "governance\narchitecture",
        ]));
        self::assertSame(303, $contract1Response->status);

        // Authority check: Unapproved contract cannot enter governed run
        self::assertNotSame(0, $this->frontDoor('enter', 'DOGFOOD-1'), 'Run cannot enter before explicit human approval.');

        // Human Contract Approval
        $approve1Response = $app->handle(new Request('POST', '/task/DOGFOOD-1/approve', body: [
            '_csrf' => $csrf,
            'actor' => 'maintainer-bob',
            'revision' => '1',
        ]));
        self::assertSame(303, $approve1Response->status);

        // =========================================================================
        // Step 3: Governed Execution Enter & Attention Interrupt
        // =========================================================================
        self::assertSame(0, $this->frontDoor('enter', 'DOGFOOD-1'), 'Enter must succeed once contract is approved.');

        $gateway = new ExecutionGateway($this->root);
        $projection = $gateway->projection('DOGFOOD-1');
        $currentStage = $projection->currentStageId ?? 'investigate';
        $bundleStage = $gateway->prepareStage('DOGFOOD-1', $currentStage);

        $clarificationResult = new StageResult(
            submissionId: 'sub:' . bin2hex(random_bytes(6)),
            taskId: 'DOGFOOD-1',
            runId: $bundleStage->runId,
            contractRevision: $bundleStage->contractRevision,
            executionPlanDigest: $bundleStage->executionPlanDigest,
            stageId: $currentStage,
            attempt: 1,
            outcome: StageOutcome::NEEDS_CLARIFICATION,
            candidateRevision: $bundleStage->candidateRevision,
            artifactReferences: [],
            validationReferences: [],
            summary: 'Ambiguity: Should greet() handle uppercase names specially?',
        );
        $gateway->submitStageResult($clarificationResult);

        // Verify owner holds Attention
        $humanGateway = new HumanDecisionGateway($this->root);
        $attention = $humanGateway->pendingAttention('DOGFOOD-1');
        self::assertNotNull($attention, 'Execution must hold pending Attention.');
        self::assertSame('Ambiguity: Should greet() handle uppercase names specially?', $attention->message);

        // Verify browser cockpit & task views display Attention panel
        $overviewHtml = $app->handle(new Request('GET', '/task/DOGFOOD-1'))->body;
        self::assertStringContainsString('id="decision"', $overviewHtml);
        self::assertStringContainsString('Ambiguity: Should greet() handle uppercase names specially?', $overviewHtml);
        self::assertStringContainsString((string) $attention->id, $overviewHtml);

        $progressHtml = $app->handle(new Request('GET', '/task/DOGFOOD-1/progress'))->body;
        self::assertStringContainsString('Attention required: stage ' . $currentStage, $progressHtml);
        self::assertStringContainsString('Resolve Attention', $progressHtml);

        $progressJson = json_decode($app->handle(new Request('GET', '/task/DOGFOOD-1/progress.json'))->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($progressJson);
        self::assertTrue($progressJson['has_human_decision'] ?? false);

        // =========================================================================
        // Step 4: Human Attention Resolution in Browser
        // =========================================================================
        $resolveResponse = $app->handle(new Request('POST', '/task/DOGFOOD-1/attention', body: [
            '_csrf' => $csrf,
            'attention_id' => $attention->id,
            'actor' => 'lead-operator-dan',
            'return_to' => 'progress',
        ]));
        self::assertSame(303, $resolveResponse->status);

        // Canonical owner verification: Attention cleared, attempt advanced
        $projectionResumed = $gateway->projection('DOGFOOD-1');
        self::assertNull($projectionResumed->attention);
        self::assertSame(2, $projectionResumed->currentAttempt);
        self::assertNull($humanGateway->pendingAttention('DOGFOOD-1'));

        // =========================================================================
        // Step 5: Implementation, Validation & Review Gate
        // =========================================================================
        file_put_contents($this->root . '/src/Greeter.php', "<?php\nfinal class Greeter { public function greet(string \$name): string { return 'Hello ' . \$name . '!'; } }\n");

        $finishStatus = $this->frontDoor('finish', 'DOGFOOD-1');
        self::assertSame(1, $finishStatus, 'Finish stops at review acknowledgement gate.');

        $decisions = $humanGateway->available('DOGFOOD-1');
        self::assertContains('acknowledge_review', $decisions->actions);
        self::assertNotNull($decisions->reviewReportSha256);

        // Acknowledge review report via browser POST
        $ackResponse = $app->handle(new Request('POST', '/task/DOGFOOD-1/review-ack', body: [
            '_csrf' => $csrf,
            'report_sha256' => $decisions->reviewReportSha256,
            'actor' => 'reviewer-carol',
        ]));
        self::assertSame(303, $ackResponse->status);

        // =========================================================================
        // Step 6: Publish Learning Finding & LearningNote
        // =========================================================================
        $layout = new ProjectLayout($this->root);
        $run = (new GovernedRunStore($this->root))->find('DOGFOOD-1');
        self::assertNotNull($run);
        $session = (new SessionStore())->activeForTask($layout->sessionsRoot(), 'DOGFOOD-1');
        self::assertNotNull($session);
        $learningRoot = WorkflowLearningRoot::forRun($this->root, $run);

        $findingCreated = (new FindingCreator())->createValidated(
            root: $learningRoot,
            taskId: 'DOGFOOD-1',
            session: $session->id,
            createdBy: 'maintainer-bob',
            scope: ['src/Greeter.php'],
            observation: 'Attention resolution must always go through typed owner operations rather than direct mutation.',
            evidence: [['type' => 'manual_verification', 'summary' => 'Proven in EndToEndWorkflowDogfoodTest.']],
            hypothesis: 'Typed ExecutionGateway and AttentionResolutionStore prevent state desynchronization.',
            validatedConclusion: 'Use typed ExecutionGateway for all attention resolutions.',
            confidence: 'high',
            sensitivity: 'public',
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: 'workflow.attention_typed_resolution',
            validationCase: new ValidationCase('Task encounters attention.', 'Resolve via gateway.', 'State stays consistent.'),
        );
        $findingId = $findingCreated->finding->id;

        (new LearningNoteService())->publish(
            $learningRoot,
            new LearningNoteDraft(
                sourceFindings: [$findingId],
                sourceProposals: [],
                tags: ['governance', 'architecture'],
                repositoryEvidence: [new LearningNoteRepositoryEvidence('src/Greeter.php', (string) hash_file('sha256', $this->root . '/src/Greeter.php'))],
                content: new LearningNoteContent(
                    title: self::PRECEDENT_TITLE,
                    context: 'A developer cockpit provides attention resolution actions.',
                    guidance: 'Always invoke typed owner gateways to resolve workflow Attention.',
                    whyItWorks: 'The owner enforces concurrency locks, validation, and attempt increments.',
                    whenToApply: 'Any interactive control plane mutation.',
                    whenNotToApply: 'Read-only projections.',
                    verification: 'Run integration test covering state progression.',
                ),
            ),
            $this->root,
        );

        // =========================================================================
        // Step 7: Task 2 Creation & Precedent Inheritance through Recall
        // =========================================================================
        $card2Response = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => $csrf,
            'card_id' => 'DOGFOOD-2',
            'title' => 'Consumer Task Using Precedent',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Downstream consumer that inherits DOGFOOD-1 precedent',
            'task_brief' => 'Inherit precedent from DOGFOOD-1.',
            'validation' => 'php -l src/Greeter.php',
            'priority' => '2',
            'assignee' => 'alice',
        ]));
        self::assertSame(303, $card2Response->status);

        $contract2Response = $app->handle(new Request('POST', '/task/DOGFOOD-2/contract', body: [
            '_csrf' => $csrf,
            'goal' => 'Consumer work adhering to governance.',
            'scope' => 'src/Greeter.php',
            'non_goals' => 'none',
            'validation' => 'php -l src/Greeter.php',
            'planned_by' => 'planner-agent',
            'acceptance_criteria' => 'validation passes',
            'tags' => "governance\narchitecture",
        ]));
        self::assertSame(303, $contract2Response->status);

        $approve2Response = $app->handle(new Request('POST', '/task/DOGFOOD-2/approve', body: [
            '_csrf' => $csrf,
            'actor' => 'maintainer-bob',
            'revision' => '1',
        ]));
        self::assertSame(303, $approve2Response->status);

        // Enter DOGFOOD-2, which compiles Recall context including DOGFOOD-1's published note
        self::assertSame(0, $this->frontDoor('enter', 'DOGFOOD-2'));

        // =========================================================================
        // Step 8: Verify Bidirectional Learning -> Recall Lineage
        // =========================================================================
        // 1. Task 2's Learning page shows the precedent was delivered, and links back to DOGFOOD-1
        $task2LearningHtml = $app->handle(new Request('GET', '/task/DOGFOOD-2/learning'))->body;
        self::assertStringContainsString('Precedents Recall delivered', $task2LearningHtml);
        self::assertStringContainsString(self::PRECEDENT_TITLE, $task2LearningHtml);
        self::assertStringContainsString('delivered', $task2LearningHtml);
        self::assertStringContainsString('href="/task/DOGFOOD-1/learning"', $task2LearningHtml, 'Must link to teaching task.');

        // 2. Task 2's Context page displays the delivered precedent
        $task2ContextHtml = $app->handle(new Request('GET', '/task/DOGFOOD-2/context'))->body;
        self::assertStringContainsString('learning-note.', $task2ContextHtml);

        // 3. Task 1's Learning page displays downstream reuse on DOGFOOD-2
        $task1LearningHtml = $app->handle(new Request('GET', '/task/DOGFOOD-1/learning'))->body;
        self::assertStringContainsString('Reused in downstream tasks (Recall delivery)', $task1LearningHtml);
        self::assertStringContainsString('href="/task/DOGFOOD-2/learning"', $task1LearningHtml, 'Must link to downstream consumer task.');

        // 4. Knowledge Finding page displays derived note and downstream reuse
        $findingHtml = $app->handle(new Request('GET', '/knowledge/findings/' . $findingId))->body;
        self::assertStringContainsString('Derived Learning Notes &amp; Downstream Reuse', $findingHtml);
        self::assertStringContainsString(self::PRECEDENT_TITLE, $findingHtml);
        self::assertStringContainsString('href="/task/DOGFOOD-2/learning"', $findingHtml);
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
