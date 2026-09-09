<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentUi\Feature\Task\ContractScopeImpact;
use voku\AgentUi\Feature\Task\ContractScopeImpactEntry;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

final class ContractScopeImpactTest extends TestCase
{
    private MapFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new MapFixture('agent_ui_scope_impact_');
        $this->writeMap();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testTheReachOutsideScopeIsTheOwnersImpactPartitionedByTheOwnersScopeRule(): void
    {
        // Greeter is declared in scope. Controller calls it and is in scope too;
        // Kernel calls Controller and is not. So exactly one file outside the
        // approved boundary can notice this change, at depth 2.
        $impact = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract(['src/Greeter.php', 'src/Controller.php']),
        );

        self::assertTrue($impact->available);
        self::assertSame(['src/Kernel.php'], $impact->filesOutsideScope);
        self::assertSame(1, $impact->filesOutsideScopeCount);
        self::assertSame(2, $impact->indexedEntryCount);
        self::assertSame(0, $impact->unindexedEntryCount);

        $greeter = $this->entry($impact, 'src/Greeter.php');
        self::assertTrue($greeter->indexed);
        self::assertSame(2, $greeter->seedCount);
        self::assertSame(['src/Kernel.php'], $greeter->reachedOutsideScope);
        self::assertSame(1, $greeter->reachedInsideScopeCount);
    }

    public function testWideningTheDeclaredScopeRemovesTheReachWithoutTouchingTheMap(): void
    {
        // Same map, same traversal from src/Greeter.php: only the approved
        // boundary moved. Adding the directory puts src/Kernel.php inside it,
        // and the owner's prefix rule is what decides that - not this class.
        $narrow = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract(['src/Greeter.php']),
        );
        $wide = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract(['src/Greeter.php', 'src/']),
        );

        self::assertSame(['src/Controller.php', 'src/Kernel.php'], $narrow->filesOutsideScope);
        self::assertSame([], $wide->filesOutsideScope);

        $greeter = $this->entry($wide, 'src/Greeter.php');
        self::assertSame(0, $greeter->reachedOutsideScopeCount);
        self::assertSame(2, $greeter->reachedInsideScopeCount);
        // The directory entry itself is not a file agent-map has facts about.
        self::assertFalse($this->entry($wide, 'src/')->indexed);
    }

    public function testAPathTheMapDoesNotIndexIsNotReportedAsHavingNoDependents(): void
    {
        $impact = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract(['src/Greeter.php', 'composer.json']),
        );

        $manifest = $this->entry($impact, 'composer.json');
        self::assertFalse($manifest->indexed);
        self::assertFalse($manifest->reachesOutsideScope());
        self::assertSame(1, $impact->unindexedEntryCount);
    }

    public function testUncertaintyFromTheOwnerSurvivesIntoTheLens(): void
    {
        // Kernel reaches Controller through a dynamic call. The owner marks that
        // path uncertain and the lens must not round it into a resolved fact.
        $impact = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract(['src/Greeter.php']),
        );

        self::assertTrue($impact->anyUncertain);
        self::assertTrue($this->entry($impact, 'src/Greeter.php')->uncertain);
    }

    public function testAContractWithoutScopeProducesNoLensRatherThanAnEmptyAnswer(): void
    {
        $impact = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract([]),
        );

        self::assertFalse($impact->available);
        self::assertTrue($impact->isEmpty());
    }

    public function testTheContractPageRendersTheReachAndItsProvenance(): void
    {
        $body = $this->render($this->contract(['src/Greeter.php', 'src/Controller.php', 'composer.json']));

        self::assertStringContainsString('What this scope reaches', $body);
        self::assertStringContainsString('1 file outside the declared scope can notice this change', $body);
        self::assertStringContainsString('/map/source?path=src%2FKernel.php', $body);
        self::assertStringContainsString('not indexed by agent-map', $body);
        self::assertStringContainsString('an absent projection, not a claim that nothing depends on them', $body);
        // The observation must never read as a judgement about the Contract.
        self::assertStringContainsString('not a verdict on the Contract', $body);
    }

    public function testAnUnbuiltMapIsNotRenderedAsNothingDependingOnTheScope(): void
    {
        unlink($this->fixture->root . '/.agent-map/php-symbols.json');

        $impact = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract(['src/Greeter.php', 'src/Controller.php']),
        );

        self::assertTrue($impact->nothingProjected());
        self::assertSame(0, $impact->indexedEntryCount);
        self::assertSame(0, $impact->filesOutsideScopeCount);

        $body = $this->render($this->contract(['src/Greeter.php', 'src/Controller.php']));
        self::assertStringContainsString('agent-map has no facts about any declared path', $body);
        self::assertStringContainsString('not the same as nothing depending on this scope', $body);
        self::assertStringNotContainsString('No file outside the declared scope reaches it', $body);
    }

    public function testTruncatedEntriesDoNotClaimThatNothingWasProjected(): void
    {
        $scope = [];
        for ($i = 1; $i <= ContractScopeImpact::MAXIMUM_ENTRIES; ++$i) {
            $scope[] = 'unindexed/path-' . $i . '.php';
        }
        // This path is indexed, but lies beyond the entry observation bound.
        $scope[] = 'src/Greeter.php';

        $impact = ContractScopeImpact::compose(
            new MapProjectionGateway($this->fixture->root),
            $this->contract($scope),
        );

        self::assertTrue($impact->entriesTruncated);
        self::assertSame(ContractScopeImpact::MAXIMUM_ENTRIES, $impact->unindexedEntryCount);
        self::assertSame(0, $impact->indexedEntryCount);
        self::assertFalse($impact->nothingProjected());
    }

    public function testTheContractPageOmitsTheLensWhenThereIsNoScopeToProjectFrom(): void
    {
        $body = $this->render($this->contract([]));

        self::assertStringNotContainsString('What this scope reaches', $body);
    }

    private function render(TaskContract $contract): string
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $map = new MapProjectionGateway($this->fixture->root);

        return $templates->render('task/contract', [
            'card' => new CardSnapshot('UI-1', 'Fixture task', 'doing', 'in_progress', '', '', '', null, null, ''),
            'contract' => $contract,
            'scope_impact' => ContractScopeImpact::compose($map, $contract),
            'map_readiness' => $map->readiness(),
            'csrf_token' => 'token',
        ]);
    }

    /** @param list<string> $scope */
    private function contract(array $scope): TaskContract
    {
        return new TaskContract(
            taskId: 'UI-1',
            goal: 'Fixture goal',
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
    }

    private function entry(ContractScopeImpact $impact, string $path): ContractScopeImpactEntry
    {
        foreach ($impact->entries as $entry) {
            if ($entry->path === $path) {
                return $entry;
            }
        }

        self::fail('No scope entry for ' . $path);
    }

    private function writeMap(): void
    {
        $this->fixture->writeMap(
            [
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
                [
                    'path' => 'src/Controller.php',
                    'sha256' => 'sha256:unverified',
                    'namespace' => 'App',
                    'symbols' => [
                        MapFixture::classSymbol('Controller', 'App\\Controller', 5, 20, [
                            MapFixture::method('index', 7, 12),
                        ]),
                    ],
                ],
                [
                    'path' => 'src/Kernel.php',
                    'sha256' => 'sha256:unverified',
                    'namespace' => 'App',
                    'symbols' => [
                        MapFixture::classSymbol('Kernel', 'App\\Kernel', 5, 20, [
                            MapFixture::method('boot', 7, 12),
                        ]),
                    ],
                ],
            ],
            [
                [
                    'id' => 'relation:controller-calls-greeter',
                    'source_id' => 'method:App\\Controller::index',
                    'kind' => 'calls',
                    'target_ids' => ['method:App\\Greeter::greet'],
                    'file' => 'src/Controller.php',
                    'line_start' => 9,
                    'line_end' => 9,
                    'resolution' => 'static',
                ],
                [
                    'id' => 'relation:kernel-calls-controller',
                    'source_id' => 'method:App\\Kernel::boot',
                    'kind' => 'calls',
                    'target_ids' => ['method:App\\Controller::index'],
                    'file' => 'src/Kernel.php',
                    'line_start' => 9,
                    'line_end' => 9,
                    'resolution' => 'dynamic',
                ],
            ],
        );
    }
}
