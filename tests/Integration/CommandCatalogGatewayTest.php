<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Cli\CommandGroup;
use voku\AgentLoop\Cli\CommandId;
use voku\AgentUi\Integration\AgentLoop\CommandCatalogGateway;

final class CommandCatalogGatewayTest extends TestCase
{
    private CommandCatalogGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new CommandCatalogGateway();
    }

    public function testAllReturnsCatalogDescriptors(): void
    {
        $all = $this->gateway->all();

        self::assertNotEmpty($all);
        self::assertArrayHasKey('quick', $all);
        self::assertArrayHasKey('enter', $all);
        self::assertArrayHasKey('finish', $all);
        self::assertArrayHasKey('board', $all);
        self::assertArrayHasKey('board:verify', $all);
        self::assertArrayHasKey('map', $all);
        self::assertArrayHasKey('commands', $all);
    }

    public function testListReturnsIndexedListOfDescriptors(): void
    {
        $list = $this->gateway->list();

        self::assertNotEmpty($list);
        self::assertSame(array_values($this->gateway->all()), $list);
    }

    public function testByGroupFiltersDescriptors(): void
    {
        $workflow = $this->gateway->byGroup(CommandGroup::Workflow);

        self::assertNotEmpty($workflow);
        foreach ($workflow as $descriptor) {
            self::assertSame(CommandGroup::Workflow, $descriptor->group);
        }

        $workflowIds = array_map(static fn($d) => $d->id->value, $workflow);
        self::assertContains('enter', $workflowIds);
        self::assertContains('finish', $workflowIds);
        self::assertContains('quick', $workflowIds);
    }

    public function testFindResolvesByEnumAndString(): void
    {
        $byEnum = $this->gateway->find(CommandId::Enter);
        $byString = $this->gateway->find('enter');

        self::assertNotNull($byEnum);
        self::assertSame($byEnum, $byString);
        self::assertSame(CommandId::Enter, $byEnum->id);
        self::assertSame('agent-loop enter <task-id> [options]', $byEnum->usage);

        self::assertNull($this->gateway->find('nonexistent-command-xyz'));
    }

    public function testResolveCommandIdFromActionStrings(): void
    {
        self::assertSame(CommandId::Enter, $this->gateway->resolveCommandId('vendor/bin/agent-loop enter TASK-123 --format=json'));
        self::assertSame(CommandId::Finish, $this->gateway->resolveCommandId('./vendor/bin/agent-loop finish TASK-123 --format=json'));
        self::assertSame(CommandId::BoardVerify, $this->gateway->resolveCommandId('agent-loop board:verify'));
        self::assertSame(CommandId::Quick, $this->gateway->resolveCommandId('bin/agent-loop quick TASK-123 "goal" --file=src/Foo.php'));
        self::assertSame(CommandId::Init, $this->gateway->resolveCommandId('vendor/bin/agent-loop init host-status --format=json'));

        self::assertNull($this->gateway->resolveCommandId('vendor/bin/agent-loop nonexistent-action'));
        self::assertNull($this->gateway->resolveCommandId('composer test'));
        self::assertNull($this->gateway->resolveCommandId('Irreducible implementation work'));
        self::assertNull($this->gateway->resolveCommandId(''));
    }
}
