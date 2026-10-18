<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Knowledge\DeliveredPrecedentComposer;
use voku\AgentUi\Feature\Knowledge\KnowledgeAction;
use voku\AgentUi\Feature\Task\TaskContextComposer;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentKanban\BoardProjectionGateway;
use voku\AgentUi\Integration\AgentLearning\LearningCatalogGateway;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationGateway;
use voku\AgentUi\View\TemplateRenderer;

/**
 * The Knowledge navigation groups the existing views by learning lifecycle. It is presentation only:
 * every route and query parameter stays reachable, and the active destination is announced semantically.
 */
final class KnowledgeNavigationTest extends TestCase
{
    private const array DESTINATIONS = [
        'overview' => '/knowledge',
        'findings' => '/knowledge?tab=findings',
        'proposals' => '/knowledge?tab=proposals',
        'rules' => '/knowledge?tab=rules',
        'archived' => '/knowledge?tab=archived',
        'analytics' => '/knowledge?tab=analytics',
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/agent_ui_knowledge_nav_' . bin2hex(random_bytes(6));
        $learning = $this->root . '/.agent-loop/learning';
        mkdir($learning . '/findings/validated', 0o775, true);
        file_put_contents(
            $learning . '/config.json',
            json_encode(['schema_version' => '1.0', 'task_id_pattern' => '^[A-Z]+-[0-9]+$'], JSON_THROW_ON_ERROR),
        );
        file_put_contents($learning . '/findings/validated/finding.2026-06-01.001.json', json_encode([
            'id' => 'finding.2026-06-01.001',
            'task_id' => 'UI-1',
            'session' => 'session_UI-1',
            'created_at' => '2026-06-01T10:00:00+00:00',
            'created_by' => 'test',
            'scope' => ['src/Test.php'],
            'observation' => 'Test observation',
            'hypothesis' => 'Test hypothesis',
            'validated_conclusion' => 'Test conclusion',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'internal',
            'evidence' => [['type' => 'manual_verification', 'summary' => 'test']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function testGroupsFollowTheLearningLifecycleInOrderAsLabelledLists(): void
    {
        $full = $this->render('overview');
        $body = $this->navigation($full);

        self::assertSame(1, preg_match('/<nav class="knowledge-nav" aria-label="Knowledge views">/', $full));
        self::assertSame(1, preg_match('/id="knowledge-nav-flow">Learning flow</', $body));
        self::assertSame(1, preg_match('/aria-labelledby="knowledge-nav-flow"/', $body));
        self::assertSame(1, preg_match('/id="knowledge-nav-history">History &amp; analysis</', $body));
        self::assertSame(1, preg_match('/aria-labelledby="knowledge-nav-history"/', $body));

        $positions = [];
        foreach (['Overview', 'Findings', 'Proposals', 'Durable Rules', 'Archived Tasks', 'Analytics &amp; Evolution', 'Dream'] as $label) {
            $position = strpos($body, '>' . $label);
            self::assertNotFalse($position, "Missing destination {$label}");
            $positions[$label] = $position;
        }
        $ordered = $positions;
        asort($ordered);
        self::assertSame(array_keys($positions), array_keys($ordered), 'Destinations must appear in lifecycle order.');
        self::assertStringNotContainsString('<script', $body, 'Navigation must work without JavaScript.');
        self::assertSame(2, substr_count($body, 'aria-hidden="true"'), 'Only the two decorative flow arrows are hidden from assistive technology.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function destinations(): iterable
    {
        foreach (self::DESTINATIONS as $tab => $href) {
            yield $tab => [$tab, $href];
        }
    }

    #[DataProvider('destinations')]
    public function testEveryExistingRouteStaysReachableAndExactlyTheActiveOneIsCurrent(string $tab, string $href): void
    {
        $body = $this->navigation($this->render($tab));

        foreach (self::DESTINATIONS as $destinationTab => $destinationHref) {
            self::assertStringContainsString('href="' . $destinationHref . '"', $body, "{$destinationTab} must stay linked.");
        }
        self::assertStringContainsString('href="/knowledge/dream"', $body);
        self::assertSame(1, substr_count($body, 'aria-current="page"'), 'Exactly one destination is announced as current.');
        self::assertSame(
            1,
            preg_match('/<a class="board-switcher__tab board-switcher__tab--active" aria-current="page" href="' . preg_quote($href, '/') . '">/', $body),
            "{$tab} must be the current destination.",
        );
    }

    private function navigation(string $page): string
    {
        if (preg_match('/<nav class="knowledge-nav".*?<\/nav>/s', $page, $matches) !== 1) {
            throw new \LogicException('Knowledge navigation not found in the rendered page.');
        }

        return $matches[0];
    }

    private function render(string $tab): string
    {
        $action = new KnowledgeAction(
            new LearningCatalogGateway($this->root),
            new TaskContextComposer(new BoardProjectionGateway($this->root), new WorkflowProjectionGateway($this->root), new HumanDecisionGateway($this->root)),
            new DeliveredPrecedentComposer(new ContextExplanationGateway($this->root), new LearningCatalogGateway($this->root)),
            new TemplateRenderer(dirname(__DIR__, 2) . '/templates'),
        );

        return $action->overview(new Request('GET', '/knowledge', $tab === 'overview' ? [] : ['tab' => $tab]))->body;
    }
}
