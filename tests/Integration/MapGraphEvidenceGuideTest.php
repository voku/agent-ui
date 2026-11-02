<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use voku\AgentUi\Integration\AgentMap\MapGraphNode;
use voku\AgentUi\Integration\AgentMap\MapGraphSnapshot;
use voku\AgentUi\Integration\AgentMap\MapReadinessSnapshot;
use voku\AgentUi\View\TemplateRenderer;

final class MapGraphEvidenceGuideTest extends TestCase
{
    public function testEmptyStructuralMapDoesNotClaimThereAreNoDependencies(): void
    {
        $body = $this->render(
            new MapReadinessSnapshot(
                status: 'ready',
                backend: 'simple-php-code-parser+structural-only',
                format: 'json',
                path: '/tmp/map.json',
                fileCount: 2,
                relationCount: 0,
            ),
            new MapGraphSnapshot('digest', 'files', 'Files', [], [], 0, 0),
        );

        self::assertStringContainsString('Map evidence coverage', $body);
        self::assertStringContainsString('zero source relations', $body);
        self::assertStringContainsString('Missing calls are unavailable evidence', $body);
        self::assertStringContainsString('An absent edge does not establish', $body);
        self::assertStringNotContainsString('Start exploring', $body);
    }

    public function testAnEmptyDisplayedEdgeSetDoesNotHideIndexedRelationsOrDiagnostics(): void
    {
        $body = $this->render(
            new MapReadinessSnapshot(
                status: 'ready',
                backend: 'simple-php-code-parser+phpstan',
                format: 'json',
                path: '/tmp/map.json',
                fileCount: 42,
                relationCount: 8,
                diagnosticCount: 2,
            ),
            new MapGraphSnapshot(
                'digest',
                'files',
                'Files',
                [new MapGraphNode('a', 'A', 'file', 1.0, file: 'src/A.php')],
                [],
                10,
                3,
            ),
        );

        self::assertStringContainsString('42</dd>', $body);
        self::assertStringContainsString('8</dd>', $body);
        self::assertStringContainsString('2</dd>', $body);
        self::assertStringContainsString('1 / 10 nodes; 0 / 3 edges', $body);
        self::assertStringContainsString('no coupling edges connect the displayed nodes', $body);
        self::assertStringContainsString('The index contains diagnostics', $body);
        self::assertStringNotContainsString('zero source relations', $body);
        self::assertStringNotContainsString('Missing calls are unavailable evidence', $body);
    }

    public function testOrientationIsBoundedAndLinksOnlyToOwnerProjectedNodes(): void
    {
        $nodes = [
            new MapGraphNode('src/Old & New.php', '<img src=x onerror=alert(1)>', 'file', 9.0, file: 'src/Old & New.php'),
            new MapGraphNode('src/Second.php', 'Second.php', 'file', 8.0, file: 'src/Second.php'),
            new MapGraphNode('src/Third.php', 'Third.php', 'file', 7.0, file: 'src/Third.php'),
            new MapGraphNode('src/Fourth.php', 'Fourth.php', 'file', 6.0, file: 'src/Fourth.php'),
        ];

        $body = $this->render(
            new MapReadinessSnapshot('ready', 'simple-php-code-parser+phpstan', 'json', '/tmp/map.json'),
            new MapGraphSnapshot('digest', 'files', 'Files', $nodes, [], 4, 0),
        );

        self::assertSame(1, preg_match('/<section class="panel" aria-label="Map orientation">(.*?)<\/section>/s', $body, $matches));
        $orientation = $matches[1] ?? '';
        self::assertNotSame('', $orientation);

        self::assertSame(3, substr_count($orientation, '<li>'));
        self::assertStringContainsString('reading order, not an application execution flow', $orientation);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $orientation);
        self::assertStringContainsString('/map?q=src%2FOld%20%26%20New.php', $orientation);
        self::assertStringNotContainsString('<img src=x', $orientation);
        self::assertStringNotContainsString('Fourth.php', $orientation);
    }

    public function testUnavailableMapDoesNotInventAnEvidenceGuide(): void
    {
        $body = $this->render(
            new MapReadinessSnapshot('missing', 'unknown', 'json', '/tmp/missing.json'),
            null,
        );

        self::assertStringContainsString('No usable code map', $body);
        self::assertStringNotContainsString('Map evidence coverage', $body);
        self::assertStringNotContainsString('Start exploring', $body);
    }

    private function render(MapReadinessSnapshot $readiness, ?MapGraphSnapshot $graph): string
    {
        return (new TemplateRenderer(dirname(__DIR__, 2) . '/templates'))->render('map/graph', [
            'readiness' => $readiness,
            'graph' => $graph,
        ]);
    }
}
