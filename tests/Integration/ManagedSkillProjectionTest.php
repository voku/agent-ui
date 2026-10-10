<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\ManagedAssetDriftProjection;
use voku\AgentLoop\Init\ManagedAssetKind;
use voku\AgentLoop\Init\RepositorySetupService;

final class ManagedSkillProjectionTest extends TestCase
{
    public function testCheckedInClaudeSkillsMatchTheInstalledOwnerSources(): void
    {
        $root = dirname(__DIR__, 2);
        $skillProjections = array_values(array_filter(
            (new RepositorySetupService($root))->managedAssetDrift(),
            static fn(ManagedAssetDriftProjection $projection): bool => $projection->target->host === 'claude'
                && $projection->target->kind === ManagedAssetKind::SKILLS,
        ));

        self::assertCount(1, $skillProjections, 'Expected exactly one Claude skill projection.');

        $projection = $skillProjections[0];
        self::assertSame(ManagedAssetDriftProjection::MANIFEST_PRESENT, $projection->manifestState, $projection->failure ?? '');

        $desired = $projection->target->desiredEntries();
        self::assertNotNull($desired, 'The owner could not resolve the desired Claude skill set.');
        sort($desired, SORT_STRING);

        self::assertSame(
            [
                'current' => $desired,
                'locally_modified' => [],
                'stale' => [],
                'incompatible' => [],
                'project_owned' => [],
                'unverifiable' => [],
            ],
            $projection->buckets(),
            'Committed Claude skills differ from current owner sources. Use the agent-loop init sync-skills/install-assets commands, then review and commit their generated diff.',
        );
    }
}
