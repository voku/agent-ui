<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Feature\Task\ContractRevisionDelta;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\HumanDecisionGateway;

final class ContractRevisionDeltaTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/agent_ui_revision_delta_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function testTheDeltaNamesWhatMovedAndWhoApprovedWhatItReplaces(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-1', 'Original goal.', ['src/A.php', 'src/B.php'], ['no rewrite'], ['composer ci'], 'planner');
        $contracts->approve('UI-1', 'approver-one');
        $contracts->revise('UI-1', 'Wider goal.', ['src/A.php', 'src/C.php'], [], ['composer ci', 'composer lint'], 'planner');

        $gateway = new HumanDecisionGateway($this->root);
        $superseded = $gateway->supersededRevisions('UI-1');
        $delta = ContractRevisionDelta::between($superseded[0], $gateway->contract('UI-1') ?? self::fail('no contract'));

        self::assertSame(1, $delta->fromRevision);
        self::assertSame(2, $delta->toRevision);
        self::assertSame('approver-one', $delta->previousApprovedBy);
        self::assertTrue($delta->previousWasApproved());
        self::assertSame('Original goal.', $delta->previousGoal);
        self::assertSame(['added' => ['src/C.php'], 'removed' => ['src/B.php']], $delta->lists['Scope']);
        self::assertSame(['added' => ['composer lint'], 'removed' => []], $delta->lists['Validation']);
        self::assertSame(['added' => [], 'removed' => ['no rewrite']], $delta->lists['Non-goals']);
        // A field nobody touched is absent, not present and empty.
        self::assertArrayNotHasKey('Acceptance criteria', $delta->lists);
    }

    public function testAnUnchangedGoalIsNotReportedAsAChange(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-2', 'Same goal.', ['src/A.php'], [], ['composer ci'], 'planner');
        $contracts->revise('UI-2', 'Same goal.', ['src/A.php', 'src/B.php'], [], ['composer ci'], 'planner');

        $gateway = new HumanDecisionGateway($this->root);
        $delta = ContractRevisionDelta::between(
            $gateway->supersededRevisions('UI-2')[0],
            $gateway->contract('UI-2') ?? self::fail('no contract'),
        );

        self::assertNull($delta->previousGoal);
        self::assertFalse($delta->isEmpty());
        self::assertSame(['added' => ['src/B.php'], 'removed' => []], $delta->lists['Scope']);
    }

    public function testARevisionThatChangedNoneOfTheComparedFieldsSaysSo(): void
    {
        // Silently rendering nothing would read as though no comparison ran.
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-3', 'Same goal.', ['src/A.php'], [], ['composer ci'], 'planner');
        $contracts->revise('UI-3', 'Same goal.', ['src/A.php'], [], ['composer ci'], 'other-planner');

        $gateway = new HumanDecisionGateway($this->root);
        $delta = ContractRevisionDelta::between(
            $gateway->supersededRevisions('UI-3')[0],
            $gateway->contract('UI-3') ?? self::fail('no contract'),
        );

        self::assertTrue($delta->isEmpty());
        self::assertSame([], $delta->lists);
    }

    public function testANeverRevisedContractHasNoPriorRevisionToCompare(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-4', 'Only goal.', ['src/A.php'], [], ['composer ci'], 'planner');

        self::assertSame([], (new HumanDecisionGateway($this->root))->supersededRevisions('UI-4'));
    }

    public function testTheComparisonIsAlwaysAgainstTheRevisionBeingReplaced(): void
    {
        // Three revisions: the reader approved revision 2, so revision 3 must
        // be compared against 2, never against the original.
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-5', 'Goal one.', ['src/A.php'], [], ['composer ci'], 'planner');
        $contracts->revise('UI-5', 'Goal two.', ['src/A.php', 'src/B.php'], [], ['composer ci'], 'planner');
        $contracts->approve('UI-5', 'approver-two');
        $contracts->revise('UI-5', 'Goal three.', ['src/A.php', 'src/B.php', 'src/C.php'], [], ['composer ci'], 'planner');

        $gateway = new HumanDecisionGateway($this->root);
        $superseded = $gateway->supersededRevisions('UI-5');
        self::assertCount(2, $superseded);

        $delta = ContractRevisionDelta::between(
            $superseded[count($superseded) - 1],
            $gateway->contract('UI-5') ?? self::fail('no contract'),
        );

        self::assertSame(2, $delta->fromRevision);
        self::assertSame('Goal two.', $delta->previousGoal);
        self::assertSame('approver-two', $delta->previousApprovedBy);
        self::assertSame(['added' => ['src/C.php'], 'removed' => []], $delta->lists['Scope']);
    }

    public function testTheTaskPageShowsWhatTheRevisionChangesBesideTheApproveForm(): void
    {
        $this->writeCard('UI-6', 'Revision delta fixture');
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-6', 'Original goal.', ['src/A.php'], [], ['composer ci'], 'planner');
        $contracts->approve('UI-6', 'approver-one');
        $contracts->revise('UI-6', 'Wider goal.', ['src/A.php', 'src/B.php'], [], ['composer ci'], 'planner');

        $body = (new Application($this->root, dirname(__DIR__, 2) . '/templates'))
            ->handle(new Request('GET', '/task/UI-6'))
            ->body;

        self::assertStringContainsString('Revision 2 replaces revision 1', $body);
        self::assertStringContainsString('approved by approver-one', $body);
        self::assertStringContainsString('Original goal.', $body);
        self::assertStringContainsString('src/B.php', $body);
    }

    public function testTheTaskPageShowsNoComparisonForAFirstRevision(): void
    {
        $this->writeCard('UI-7', 'First revision fixture');
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-7', 'Only goal.', ['src/A.php'], [], ['composer ci'], 'planner');

        $body = (new Application($this->root, dirname(__DIR__, 2) . '/templates'))
            ->handle(new Request('GET', '/task/UI-7'))
            ->body;

        self::assertStringNotContainsString('replaces revision', $body);
    }

    public function testThePageComparesAgainstTheReplacedRevisionNotTheOriginal(): void
    {
        // Three revisions. The reader approved revision 2, so the page must
        // describe 3 against 2. Comparing against revision 1 would report a
        // change the reader already agreed to as though it were new.
        $this->writeCard('UI-8', 'Third revision fixture');
        $contracts = new TaskContractStore($this->root);
        $contracts->create('UI-8', 'Goal one.', ['src/A.php'], [], ['composer ci'], 'planner');
        $contracts->revise('UI-8', 'Goal two.', ['src/A.php', 'src/B.php'], [], ['composer ci'], 'planner');
        $contracts->approve('UI-8', 'approver-two');
        $contracts->revise('UI-8', 'Goal three.', ['src/A.php', 'src/B.php', 'src/C.php'], [], ['composer ci'], 'planner');

        $body = (new Application($this->root, dirname(__DIR__, 2) . '/templates'))
            ->handle(new Request('GET', '/task/UI-8'))
            ->body;

        self::assertStringContainsString('Revision 3 replaces revision 2', $body);
        self::assertStringContainsString('approved by approver-two', $body);
        self::assertStringContainsString('Goal two.', $body);
        self::assertStringNotContainsString('Goal one.', $body);
        // src/B.php arrived in revision 2 and is not part of this decision.
        self::assertStringNotContainsString('+ src/B.php', $body);
    }

    private function writeCard(string $id, string $title): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/' . $id . '.md',
            '# ' . $id . ': ' . $title . "\n\n"
            . "- **Lane:** BACKLOG\n"
            . "- **Status:** todo\n\n"
            . "## Summary\n\n" . $title . "\n",
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
