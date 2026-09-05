<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

/**
 * Released-consumer replay for agent-loop#349 using real agent-ui task semantics:
 * issue #16 teaches the released-owner-before-consumer rule, then issue #24 enters
 * normally and receives that durable precedent through Recall.
 */
final class LearningNoteReturnLoopDogfoodTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-learning-note-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root, 0o775, true)) {
            throw new RuntimeException('Unable to create LearningNote replay root.');
        }

        $composerJson = dirname(__DIR__, 2) . '/composer.json';
        if (!copy($composerJson, $this->root . '/composer.json')) {
            throw new RuntimeException('Unable to copy agent-ui composer.json into the replay root.');
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $directories = [$this->root];
        for ($index = 0; $index < count($directories); ++$index) {
            foreach (new FilesystemIterator($directories[$index], FilesystemIterator::SKIP_DOTS) as $item) {
                if (!$item instanceof SplFileInfo) {
                    throw new RuntimeException('FilesystemIterator did not return file metadata.');
                }
                if ($item->isDir() && !$item->isLink()) {
                    $directories[] = $item->getPathname();
                    continue;
                }
                unlink($item->getPathname());
            }
        }

        foreach (array_reverse($directories) as $directory) {
            rmdir($directory);
        }
    }

    public function testReleasedOwnerLearningFromIssue16HelpsIssue24WithoutTransientContext(): void
    {
        $layout = new ProjectLayout($this->root);
        $contracts = new TaskContractStore($this->root);

        $taskA = 'AGENT-UI-16';
        $contracts->create(
            $taskA,
            'Build deterministic Prompt Workbench backed by Recall and workflow authority.',
            ['composer.json'],
            ['dependencies', 'release', 'owner-api'],
            ['composer validate --strict'],
            'agent-ui-maintainer',
        );
        $contracts->approve($taskA, 'agent-ui-maintainer');

        $dispatcherA = new Dispatcher($this->root);
        $recallRunnerA = static function (array $recallRest) use ($dispatcherA): int {
            /** @var list<string> $recallRest */
            return $dispatcherA->run([
                'agent-loop',
                'recall',
                ...$recallRest,
            ]);
        };
        $appA = new HostFrontDoorApplication($this->root, $recallRunnerA);

        $enterA = $this->runApp($appA, 'enter', [$taskA, '--format=json']);
        self::assertSame(0, $enterA['exit'], json_encode($enterA['payload'], JSON_THROW_ON_ERROR));

        $finishPrep = $this->runApp($appA, 'finish', [$taskA, '--format=json']);
        self::assertSame(1, $finishPrep['exit']);
        $reviewReport = (new WorkflowReviewReportReader($this->root))->read($taskA);
        $reviewSha = $reviewReport['sha256'] ?? null;
        self::assertIsString($reviewSha);

        $runA = (new GovernedRunStore($this->root))->find($taskA);
        self::assertNotNull($runA);
        $sessionStore = new SessionStore();
        $sessionA = $sessionStore->activeForTask($layout->sessionsRoot(), $taskA);
        self::assertNotNull($sessionA);
        $learningRoot = WorkflowLearningRoot::forRun($this->root, $runA);

        $finding = (new FindingCreator())->createValidated(
            root: $learningRoot,
            taskId: $taskA,
            session: $sessionA->id,
            createdBy: 'agent-ui-maintainer',
            scope: ['composer.json'],
            observation: 'Issue #16 required released owner APIs before the downstream UI integration instead of dev-main coupling.',
            evidence: [[
                'type' => 'manual_verification',
                'summary' => 'Verified against agent-ui#16: replace dev-main with the latest compatible published stable owner before integration.',
            ]],
            hypothesis: 'Release the semantic owner first, then consume the stable owner contract downstream.',
            validatedConclusion: 'Cross-package UI work should consume a released semantic-owner API instead of dev-main or private storage.',
            confidence: 'high',
            sensitivity: 'public',
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: 'consumer.release_owner_before_integration',
            validationCase: new ValidationCase(
                given: 'A downstream package needs a newly added owner capability.',
                when: 'The semantic owner capability is released before downstream integration.',
                then: 'The consumer can prove compatibility against an immutable supported package graph without dev-main coupling.',
            ),
        );
        $findingId = $finding->finding->id;

        $learningService = new LearningNoteService();
        $preparedNote = $learningService->prepare($learningRoot, [$findingId], $this->root);
        self::assertSame('consumer.release_owner_before_integration', $preparedNote->patternKey);

        $finishClose = $this->runApp($appA, 'finish', [
            $taskA,
            '--format=json',
            '--reviewed-report-sha256',
            $reviewSha,
            '--learning',
            'findings_recorded',
            '--learning-reason',
            'Released owner APIs are a reusable cross-package integration boundary.',
            '--by',
            'agent-ui-maintainer',
            '--finding',
            $findingId,
        ]);
        self::assertSame(0, $finishClose['exit'], json_encode($finishClose['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($finishClose['payload']['complete'] ?? false);
        self::assertSame('none', $finishClose['payload']['next_action_kind'] ?? null);
        self::assertSame([[
            'kind' => 'learning_note',
            'finding_ids' => [$findingId],
            'skill' => 'agent-learning-note',
        ]], $finishClose['payload']['optional_follow_ups'] ?? null);

        $removedSessions = $sessionStore->prune($layout->sessionsRoot(), 0, [SessionStatus::DONE]);
        self::assertContains($sessionA->id, $removedSessions);
        self::assertDirectoryDoesNotExist($sessionA->path);

        unset($dispatcherA, $recallRunnerA, $appA, $enterA, $finishPrep, $finishClose, $reviewReport, $runA, $sessionA);

        $sourceSha = (string) hash_file('sha256', $this->root . '/composer.json');
        $publishedNote = $learningService->publish(
            $learningRoot,
            new LearningNoteDraft(
                sourceFindings: [$findingId],
                sourceProposals: [],
                tags: ['dependencies', 'release', 'owner-api'],
                repositoryEvidence: [
                    new LearningNoteRepositoryEvidence('composer.json', $sourceSha),
                ],
                content: new LearningNoteContent(
                    title: 'Release semantic owners before downstream integration',
                    context: 'A downstream agent-* package needs a capability that was just added to its semantic owner.',
                    guidance: 'Release the owner API first, then consume that stable version downstream. Do not use dev-main or owner-private storage as the final integration path.',
                    whyItWorks: 'The downstream proof then exercises the same immutable package boundary that real consumers install.',
                    whenToApply: 'Cross-package features where an owner API must land before a consumer can use it.',
                    whenNotToApply: 'Purely internal changes that do not cross a package ownership boundary.',
                    verification: 'Resolve the released dependency graph and run the downstream package CI plus the relevant installed-consumer replay.',
                    symptoms: 'A consumer requires dev-main, a VCS override, a sibling checkout, or direct access to owner-private storage.',
                    failedApproaches: ['Keeping dev-main as final evidence.', 'Writing owner-private JSON from the consumer.'],
                    rootCause: 'Consumer work started before the semantic owner boundary was released.',
                    examples: ['agent-ui consumes tagged agent-loop and agent-loop-runner owner contracts.'],
                ),
            ),
            $this->root,
        );

        $taskB = 'AGENT-UI-24';
        $contractsB = new TaskContractStore($this->root);
        $contractsB->create(
            $taskB,
            'Consume the first tagged agent-loop-runner release and remove the dev stability fallback.',
            ['composer.json'],
            ['dependencies', 'release', 'runner'],
            ['composer validate --strict'],
            'agent-ui-maintainer',
        );
        $contractsB->approve($taskB, 'agent-ui-maintainer');

        $dispatcherB = new Dispatcher($this->root);
        $recallRunnerB = static function (array $recallRest) use ($dispatcherB): int {
            /** @var list<string> $recallRest */
            return $dispatcherB->run([
                'agent-loop',
                'recall',
                ...$recallRest,
            ]);
        };
        $appB = new HostFrontDoorApplication($this->root, $recallRunnerB);

        $enterB = $this->runApp($appB, 'enter', [$taskB, '--format=json']);
        self::assertSame(0, $enterB['exit'], json_encode($enterB['payload'], JSON_THROW_ON_ERROR));

        $recallDir = $layout->recallRoot() . '/' . $taskB;
        $recallOutput = (new CompiledRecallOutputReader())->read($recallDir);
        self::assertNotNull($recallOutput);

        $precedentFacts = array_values(array_filter(
            $recallOutput->facts(),
            static fn ($fact): bool => $fact->type === 'learning_precedent',
        ));
        self::assertCount(1, $precedentFacts);
        $precedent = $precedentFacts[0];

        self::assertSame($publishedNote->id, $precedent->payload['note_id']);
        self::assertSame('consumer.release_owner_before_integration', $precedent->payload['pattern_key']);
        self::assertSame('agent-learning:' . $publishedNote->id, $precedent->sourceRef);
        self::assertSame([$findingId], $precedent->payload['source_findings']);
        self::assertSame($publishedNote->digest, $precedent->payload['note_digest']);
        self::assertSame('current', $precedent->payload['evidence_state']);
        self::assertTrue($precedent->payload['render']);
        self::assertContains('scope_match', (array) ($precedent->payload['match_reasons'] ?? []));

        $systemPrompt = (string) file_get_contents($recallDir . '/system.md');
        self::assertStringContainsString('Relevant Learning Precedents', $systemPrompt);
        self::assertStringContainsString('Release semantic owners before downstream integration', $systemPrompt);
        self::assertStringContainsString('Do not use dev-main', $systemPrompt);

        $runBOutcome = str_contains($systemPrompt, 'Release semantic owners before downstream integration')
            ? 'HELPED'
            : 'MISSING';
        self::assertSame('HELPED', $runBOutcome);
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function runApp(HostFrontDoorApplication $app, string $command, array $args): array
    {
        ob_start();
        try {
            $exit = $app->run($command, $args);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Application JSON did not decode to an object: ' . $stdout);
        }

        /** @var array<string, mixed> $payload */
        return ['exit' => $exit, 'payload' => $payload];
    }
}
