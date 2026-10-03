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
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentSession\SessionStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * The learning ladder, end to end, through the page a developer reads.
 *
 * Task APP-1 runs governed work and records a Finding; a LearningNote is published from it;
 * the later task APP-2 enters through agent-loop's front door, and Recall compiles that note
 * into its context. `/task/APP-2/learning` then has to answer "what did this task inherit,
 * and from where" from owner facts only: Recall's persisted explanation says the note was
 * delivered, and Learning's own chain (note -> source Finding -> task) says APP-1 taught it.
 *
 * Delivery is the one claim made. The page must not say the note helped, was applied, or
 * changed the outcome: nothing in the owners proves that.
 */
final class LearningReturnLoopPageTest extends TestCase
{
    private const TITLE = 'Release semantic owners before downstream integration';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-return-loop-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create the return-loop fixture root.');
        }
        copy(dirname(__DIR__, 2) . '/composer.json', $this->root . '/composer.json');
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testALaterTaskShowsWhichTaskTaughtTheNoteRecallDeliveredToIt(): void
    {
        $app = $this->ladder();

        $main = $this->main($app, '/task/APP-2/learning');

        self::assertStringContainsString('Precedents Recall delivered', $main);
        self::assertStringContainsString(self::TITLE, $main);
        self::assertStringContainsString('delivered', $main);
        self::assertStringContainsString('href="/task/APP-1/learning"', $main, 'The note must lead back to the task that taught it.');
    }

    public function testDeliveryIsNotPresentedAsEffect(): void
    {
        $app = $this->ladder();

        $main = $this->main($app, '/task/APP-2/learning');

        self::assertStringContainsString('Delivery is shown, not effect', $main);
        foreach (['helpful', 'applied', 'improved', 'prevented'] as $claim) {
            self::assertStringNotContainsString($claim, strtolower($this->panel($main)), 'No owner proves "' . $claim . '".');
        }
    }

    public function testATaskWhoseRecallContextWasNeverCompiledSaysSoInsteadOfNoPrecedents(): void
    {
        $app = $this->ladder();
        $this->card($app, 'APP-3');

        $panel = $this->panel($this->main($app, '/task/APP-3/learning'));

        self::assertStringContainsString('No persisted compiled context exists', $panel);
        self::assertStringNotContainsString('No learning precedent was part of', $panel, 'Not compiled is not the same as nothing delivered.');
    }

    public function testTheTaskThatTaughtTheNoteWasNotHandedItsOwnNote(): void
    {
        $app = $this->ladder();

        $panel = $this->panel($this->main($app, '/task/APP-1/learning'));

        self::assertStringNotContainsString(self::TITLE, $panel);
    }

    private function ladder(): Application
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $this->card($app, 'APP-1');
        $this->card($app, 'APP-2');

        $layout = new ProjectLayout($this->root);
        $contracts = new TaskContractStore($this->root);
        $contracts->create(taskId: 'APP-1', goal: 'Build the deterministic Prompt Workbench.', scope: ['composer.json'], nonGoals: [], validation: ['composer validate --strict'], plannedBy: 'planner', tags: ['dependencies', 'release', 'owner-api']);
        $contracts->approve('APP-1', 'maintainer');
        self::assertSame(0, $this->frontDoor('enter', 'APP-1'));
        $finish = $this->frontDoorPayload('finish', 'APP-1', []);
        self::assertSame(1, $finish['exit']);

        $run = (new GovernedRunStore($this->root))->find('APP-1');
        self::assertNotNull($run);
        $session = (new SessionStore())->activeForTask($layout->sessionsRoot(), 'APP-1');
        self::assertNotNull($session);
        $learningRoot = WorkflowLearningRoot::forRun($this->root, $run);

        $finding = (new FindingCreator())->createValidated(
            root: $learningRoot,
            taskId: 'APP-1',
            session: $session->id,
            createdBy: 'maintainer',
            scope: ['composer.json'],
            observation: 'Downstream UI work required released owner APIs instead of dev-main coupling.',
            evidence: [['type' => 'manual_verification', 'summary' => 'Verified against the downstream release flow.']],
            hypothesis: 'Release the semantic owner first, then consume the stable contract.',
            validatedConclusion: 'Cross-package UI work should consume a released owner API, not dev-main.',
            confidence: 'high',
            sensitivity: 'public',
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: 'consumer.release_owner_before_integration',
            validationCase: new ValidationCase('A downstream package needs a new owner capability.', 'The owner is released first.', 'The consumer proves compatibility without dev-main.'),
        );
        $findingId = $finding->finding->id;

        (new LearningNoteService())->publish(
            $learningRoot,
            new LearningNoteDraft(
                sourceFindings: [$findingId],
                sourceProposals: [],
                tags: ['dependencies', 'release', 'owner-api'],
                repositoryEvidence: [new LearningNoteRepositoryEvidence('composer.json', (string) hash_file('sha256', $this->root . '/composer.json'))],
                content: new LearningNoteContent(
                    title: self::TITLE,
                    context: 'A downstream package needs a capability just added to its semantic owner.',
                    guidance: 'Release the owner API first, then consume that stable version downstream.',
                    whyItWorks: 'The downstream proof exercises the same immutable boundary consumers install.',
                    whenToApply: 'Cross-package features where an owner API must land first.',
                    whenNotToApply: 'Purely internal changes.',
                    verification: 'Resolve the released dependency graph and run downstream CI.',
                ),
            ),
            $this->root,
        );

        $contracts->create(taskId: 'APP-2', goal: 'Consume the first tagged runner release.', scope: ['composer.json'], nonGoals: [], validation: ['composer validate --strict'], plannedBy: 'planner', tags: ['dependencies', 'release', 'runner']);
        $contracts->approve('APP-2', 'maintainer');
        self::assertSame(0, $this->frontDoor('enter', 'APP-2'));

        return new Application($this->root, dirname(__DIR__, 2) . '/templates');
    }

    private function card(Application $app, string $id): void
    {
        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => (new CsrfTokenManager())->token(),
            'card_id' => $id,
            'title' => 'Card ' . $id,
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Summary',
            'task_brief' => 'Brief',
            'validation' => 'composer validate --strict',
            'priority' => '2',
            'assignee' => 'dev',
        ]));
        self::assertSame(303, $created->status);
    }

    private function frontDoor(string $command, string $taskId): int
    {
        return $this->frontDoorPayload($command, $taskId, [])['exit'];
    }

    /**
     * @param list<string> $extra
     * @return array{exit: int}
     */
    private function frontDoorPayload(string $command, string $taskId, array $extra): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorApplication($this->root))->run($command, [$taskId, '--format=json', ...$extra]);
            $out = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertJson($out, 'The front door must answer in JSON.');

        return ['exit' => $exit];
    }

    private function main(Application $app, string $path): string
    {
        $response = $app->handle(new Request('GET', $path));
        self::assertSame(200, $response->status, $path);
        $start = strpos($response->body, '<main');
        $end = strpos($response->body, '</main>');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        return substr($response->body, $start, $end - $start);
    }

    /** The delivered-precedents panel only, so the rest of the page cannot satisfy an assertion. */
    private function panel(string $main): string
    {
        $start = strpos($main, 'Precedents Recall delivered');
        self::assertNotFalse($start, 'The panel is missing.');
        $end = strpos($main, '<p class="eyebrow">', $start + 10);

        return substr($main, $start, ($end === false ? strlen($main) : $end) - $start);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            $item->isDir() && !$item->isLink() ? $this->removeDirectory($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
