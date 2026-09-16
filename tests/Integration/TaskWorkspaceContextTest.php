<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Feature\Task\TaskContext;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

/**
 * A task is one workspace, so its context does not depend on which view you opened.
 *
 * The ten task views were ten pages that shared an id. `/task/{id}/evidence` told
 * you what the evidence was without telling you which task it belonged to, where
 * the workflow stood, or what it expected next; the reader either remembered
 * those or went back to `/task/{id}` to look them up. The navigation that would
 * have taken them there sat at the very bottom of the page.
 *
 * What is pinned here is not the markup. It is that the same three owner facts -
 * identity, state, canonical next action - reach every view, that Loop's sentence
 * arrives unedited, and that a reader meets the context before the page body.
 */
final class TaskWorkspaceContextTest extends TestCase
{
    /** Every task view, as a reader reaches them. */
    private const VIEWS = [
        '', '/progress', '/contract', '/context', '/work',
        '/evidence', '/history', '/prompts', '/learning', '/edit',
    ];

    private string $root;
    private string $templates;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-ctx-' . bin2hex(random_bytes(6));
        $this->templates = dirname(__DIR__, 2) . '/templates';

        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    /** Which task you are looking at must not depend on which of its ten views you opened. */
    public function testEveryTaskViewCarriesTheSameTaskIdentity(): void
    {
        $app = $this->applicationWithCard();

        foreach (self::VIEWS as $view) {
            $body = $this->view($app, $view);

            self::assertStringContainsString('class="task-context"', $body, $view . ' lost the task context');
            self::assertStringContainsString('Build login system', $body, $view . ' lost the task title');
            self::assertStringContainsString('APP-1', $body, $view . ' lost the task id');
        }
    }

    /**
     * The next action is agent-loop's sentence, not the UI's reading of it.
     *
     * Asserting a paraphrase would pass while the UI quietly summarised, ranked or
     * conditioned the owner's answer, which is the one thing a control plane that
     * holds no authority must not do.
     */
    public function testEveryTaskViewPrintsLoopsNextActionVerbatim(): void
    {
        $app = $this->applicationWithCard();
        $expected = (new WorkflowProjectionGateway($this->root))->task('APP-1')->nextAction;

        self::assertNotSame('', $expected);

        foreach (self::VIEWS as $view) {
            self::assertStringContainsString(
                TemplateRenderer::escape($expected),
                $this->view($app, $view),
                $view . ' does not carry the canonical next action',
            );
        }
    }

    /** Context and navigation come before the view's own answer, for the eye and the keyboard. */
    public function testTheContextAndItsNavigationPrecedeThePageBody(): void
    {
        $app = $this->applicationWithCard();

        foreach (self::VIEWS as $view) {
            $body = $this->view($app, $view);

            $context = strpos($body, 'class="task-context"');
            $nav = strpos($body, '<nav class="task-nav"');
            $heading = strpos($body, '<h1');

            self::assertIsInt($context, $view);
            self::assertIsInt($nav, $view);
            self::assertIsInt($heading, $view);
            self::assertLessThan($nav, $context, $view . ' puts its navigation above its context');
            self::assertLessThan($heading, $nav, $view . ' still trails its navigation behind the page body');
        }
    }

    /** Deep links are the reason the grouping had to preserve every route. */
    public function testEveryTaskViewStillAnswersOnItsOwnUrl(): void
    {
        $app = $this->applicationWithCard();

        foreach (self::VIEWS as $view) {
            self::assertSame(
                200,
                $app->handle(new Request('GET', '/task/APP-1' . $view))->status,
                '/task/APP-1' . $view . ' is no longer reachable',
            );
        }
    }

    /**
     * An id no board holds is a missing page, not a broken control plane.
     *
     * A governed task can exist in agent-loop with no agent-kanban card - that is
     * how this was found - and the operator used to get a 500 and a log line to
     * read, for what is an ordinary 404.
     */
    public function testATaskWithNoBoardCardIsNotFoundRatherThanAServerError(): void
    {
        $app = new Application($this->root, $this->templates);

        $response = $app->handle(new Request('GET', '/task/APP-404'));

        self::assertSame(404, $response->status);
        self::assertStringContainsString('APP-404', $response->body);
    }

    /**
     * A state this UI has never heard of renders as itself.
     *
     * Owners add vocabulary. Presentation may choose a colour for a word it knows,
     * but it may not decide what an unknown word means, so the neutral styling and
     * the owner's own string are what a new state must produce.
     */
    public function testAnOwnerStateTheUiDoesNotKnowRendersNeutrally(): void
    {
        $body = $this->renderContext(new TaskContext(
            taskId: 'APP-1',
            title: 'Build login system',
            lane: 'DOING',
            workflowState: 'awaiting_quorum',
            nextAction: 'agent-loop quorum APP-1',
            nextActionKind: 'quorum_required',
        ));

        // Neutral, not guessed into one of the tones the UI happens to style.
        self::assertStringContainsString('pill--neutral">awaiting quorum', $body);
        self::assertStringContainsString('pill--neutral">quorum required', $body);
        self::assertStringNotContainsString('pill--ok">awaiting quorum', $body);
        self::assertStringNotContainsString('pill--blocked">awaiting quorum', $body);
        // The owner's own command is never rewritten, whatever the UI makes of the state.
        self::assertStringContainsString('agent-loop quorum APP-1', $body);
    }

    /** Provenance stays attached: the reader can see which owner said which fact. */
    public function testEachFactNamesTheOwnerItCameFrom(): void
    {
        $body = $this->renderContext(new TaskContext(
            taskId: 'APP-1',
            title: 'Build login system',
            lane: 'DOING',
            workflowState: 'incomplete',
            nextAction: 'agent-loop enter APP-1',
            nextActionKind: 'command',
        ));

        self::assertStringContainsString('agent-kanban · lane', $body);
        self::assertStringContainsString('agent-loop · run state', $body);
        self::assertStringContainsString('agent-loop · canonical next action', $body);
    }

    /**
     * The partial is rendered through a page that owns it, so the assertions
     * describe what a reader actually receives rather than a detached fragment.
     */
    private function renderContext(TaskContext $context): string
    {
        return (new TemplateRenderer($this->templates))->render('history/index', [
            'audit' => (new AuditTrailGateway($this->root))->task($context->taskId),
            'task_context' => $context,
        ]);
    }

    /** Builds the control plane over a throwaway board holding one card, through its real create route. */
    private function applicationWithCard(): Application
    {
        $app = new Application($this->root, $this->templates);
        $csrf = (new CsrfTokenManager())->token();

        $created = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => $csrf,
            'card_id' => 'APP-1',
            'title' => 'Build login system',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Support secure user login',
            'task_brief' => 'Detailed brief for building login.',
            'validation' => 'composer test',
            'priority' => '2',
            'assignee' => 'developer',
        ]));
        self::assertSame(303, $created->status);

        return $app;
    }

    /** Fetches one task view through the real router, failing the test if it stopped answering. */
    private function view(Application $app, string $suffix): string
    {
        $response = $app->handle(new Request('GET', '/task/APP-1' . $suffix));
        self::assertSame(200, $response->status, '/task/APP-1' . $suffix);

        return $response->body;
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
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
