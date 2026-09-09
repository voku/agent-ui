<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentUi\Feature\Task\ContractScopeImpact;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final class ContractScopeImpactBoundedHeadlineTest extends TestCase
{
    private MapFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new MapFixture('agent_ui_scope_bound_');
        $this->fixture->writeMap([
            [
                'path' => 'src/Greeter.php',
                'sha256' => 'sha256:unverified',
                'namespace' => 'App',
                'symbols' => [
                    MapFixture::classSymbol('Greeter', 'App\\Greeter', 5, 12, [
                        MapFixture::method('greet', 7, 11),
                    ]),
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();

        parent::tearDown();
    }

    public function testTruncatedScopeCannotRenderACompleteZeroReachHeadline(): void
    {
        $scope = [];
        for ($i = 1; $i <= ContractScopeImpact::MAXIMUM_ENTRIES; ++$i) {
            $scope[] = 'unindexed/path-' . $i . '.php';
        }
        // This path is indexed, but the bounded observation never reaches it.
        $scope[] = 'src/Greeter.php';

        $contract = new TaskContract(
            taskId: 'UI-BOUND',
            goal: 'Keep bounded scope evidence honest.',
            scope: $scope,
            nonGoals: [],
            validation: ['composer ci'],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-01-01T00:00:00+00:00',
            updatedAt: '2026-01-01T00:00:00+00:00',
            path: $this->fixture->root . '/contract.json',
            plannedBy: 'fixture',
        );

        $map = new MapProjectionGateway($this->fixture->root);
        $impact = ContractScopeImpact::compose($map, $contract);

        self::assertTrue($impact->entriesTruncated);
        self::assertSame(0, $impact->indexedEntryCount);
        self::assertFalse($impact->nothingProjected());

        $body = (new TemplateRenderer(dirname(__DIR__, 2) . '/templates'))->render('task/contract', [
            'card' => new CardSnapshot('UI-BOUND', 'Bounded scope fixture', 'doing', 'in_progress', '', '', '', null, null, ''),
            'contract' => $contract,
            'scope_impact' => $impact,
            'map_readiness' => $map->readiness(),
            'csrf_token' => 'token',
        ]);

        self::assertStringContainsString('No outside-scope reach was observed in the bounded analysis', $body);
        self::assertStringNotContainsString('No file outside the declared scope reaches it', $body);
        self::assertStringContainsString('Only the first 25 declared paths were analysed.', $body);
    }
}
