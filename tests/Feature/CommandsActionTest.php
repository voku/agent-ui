<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Feature;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Commands\CommandsAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\CommandCatalogGateway;
use voku\AgentUi\View\TemplateRenderer;

final class CommandsActionTest extends TestCase
{
    private CommandsAction $action;

    protected function setUp(): void
    {
        $templates = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $gateway = new CommandCatalogGateway();
        $this->action = new CommandsAction($gateway, $templates);
    }

    public function testIndexRendersCommandCatalogOverview(): void
    {
        $response = ($this->action)(new Request('GET', '/commands'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Commands Reference', $response->body);
        self::assertStringContainsString('Command Catalog · agent-loop', $response->body);
        self::assertStringContainsString('quick', $response->body);
        self::assertStringContainsString('enter', $response->body);
        self::assertStringContainsString('finish', $response->body);
        self::assertStringContainsString('board:verify', $response->body);
        self::assertStringContainsString('voku/agent-loop', $response->body);
        self::assertStringContainsString('voku/agent-kanban', $response->body);
    }

    public function testFilterByGroup(): void
    {
        $response = ($this->action)(new Request('GET', '/commands', query: ['group' => 'workflow']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('enter', $response->body);
        self::assertStringContainsString('finish', $response->body);
        self::assertStringNotContainsString('board:verify', $response->body);
    }

    public function testFilterByOwner(): void
    {
        $response = ($this->action)(new Request('GET', '/commands', query: ['owner' => 'voku/agent-kanban']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('board:verify', $response->body);
        self::assertStringNotContainsString('agent-loop finish', $response->body);
    }

    public function testFilterBySearchQuery(): void
    {
        $response = ($this->action)(new Request('GET', '/commands', query: ['q' => 'surgical']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('quick', $response->body);
        self::assertStringNotContainsString('board:verify', $response->body);
    }

    public function testEmptyFilterShowsEmptyState(): void
    {
        $response = ($this->action)(new Request('GET', '/commands', query: ['q' => 'this-matches-no-commands-xyz']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('No commands match your filter criteria', $response->body);
    }
}
