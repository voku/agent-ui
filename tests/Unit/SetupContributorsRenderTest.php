<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\RepositorySetupContributor;
use voku\AgentLoop\Init\RepositorySetupNextActionKind;
use voku\AgentLoop\Init\RepositorySetupProjection;
use voku\AgentLoop\Init\RepositorySetupSelection;
use voku\AgentUi\View\TemplateRenderer;

final class SetupContributorsRenderTest extends TestCase
{
    public function testOverviewRendersAssetContributors(): void
    {
        $projection = new RepositorySetupProjection(
            host: 'codex',
            selection: RepositorySetupSelection::AUTO,
            runtime: null,
            integration: null,
            policyDetail: null,
            policyPath: null,
            runtimeBoundary: null,
            nextActionKind: RepositorySetupNextActionKind::NONE,
            nextAction: null,
            contributors: [
                new RepositorySetupContributor(
                    owner: 'voku/agent-loop',
                    scope: RepositorySetupContributor::SCOPE_CONSUMER,
                    skillCount: 12,
                    subagentCount: 4,
                    instructionCount: 1,
                ),
                new RepositorySetupContributor(
                    owner: 'voku/agent-learning',
                    scope: RepositorySetupContributor::SCOPE_CONSUMER,
                    skillCount: 5,
                    subagentCount: 0,
                    instructionCount: 1,
                ),
            ],
        );

        $renderer = new TemplateRenderer(dirname(__DIR__, 2) . '/templates');
        $html = $renderer->render('setup/index', [
            'hosts' => [
                'codex' => [
                    'projection' => $projection,
                    'legal' => [],
                ],
            ],
            'csrf' => 'test-csrf-token',
        ]);

        self::assertStringContainsString('Asset contributors (2)', $html);
        self::assertStringContainsString('<strong>voku/agent-loop</strong>', $html);
        self::assertStringContainsString('(consumer)', $html);
        self::assertStringContainsString('12 skills, 4 subagents, 1 instruction', $html);
        self::assertStringContainsString('<strong>voku/agent-learning</strong>', $html);
        self::assertStringContainsString('5 skills, 0 subagents, 1 instruction', $html);
    }
}
