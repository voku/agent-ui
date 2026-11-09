<?php

declare(strict_types=1);

use voku\AgentUi\Integration\AgentMap\MapProjectionGateway;
use voku\AgentUi\View\TemplateRenderer;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$output = $argv[1] ?? $root . '/var/architecture-evidence';
if (!is_dir($output) && !mkdir($output, 0o775, true) && !is_dir($output)) {
    throw new RuntimeException('Unable to create evidence directory: ' . $output);
}

$map = new MapProjectionGateway($root);
$readiness = $map->readiness();
$graph = $readiness->isUsable() ? $map->graph() : null;
$html = (new TemplateRenderer($root . '/templates'))->render('map/graph', [
    'readiness' => $readiness,
    'graph' => $graph,
]);

$checks = [
    'current_index' => $readiness->isReady(),
    'indexed_files_present' => $readiness->fileCount > 0,
    'graph_nodes_present' => $graph !== null && $graph->nodes !== [],
    'coverage_visible' => str_contains($html, 'aria-label="Map evidence coverage"'),
    'orientation_visible' => str_contains($html, 'aria-label="Map orientation"'),
];

/**
 * This is a smoke experiment, not a completeness benchmark.
 * The owner provides all map counts and state; the UI only renders them.
 *
 * @var array{
 *     schema: string,
 *     backend: string,
 *     readiness: string,
 *     indexed_files: int,
 *     indexed_relations: int,
 *     diagnostics: int,
 *     shown_nodes: int,
 *     total_nodes: int,
 *     shown_edges: int,
 *     total_edges: int,
 *     checks: array<string, bool>
 * } $report
 */
$report = [
    'schema' => 'agent-ui-architecture-evidence@1',
    'backend' => $readiness->backend,
    'readiness' => $readiness->status,
    'indexed_files' => $readiness->fileCount,
    'indexed_relations' => $readiness->relationCount,
    'diagnostics' => $readiness->diagnosticCount,
    'shown_nodes' => $graph === null ? 0 : count($graph->nodes),
    'total_nodes' => $graph?->totalNodeCount ?? 0,
    'shown_edges' => $graph === null ? 0 : count($graph->edges),
    'total_edges' => $graph?->totalEdgeCount ?? 0,
    'checks' => $checks,
];

$serialized = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output . '/report.json', $serialized) === false) {
    throw new RuntimeException('Unable to write architecture evidence report.');
}

fwrite(STDOUT, $serialized);
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Architecture evidence smoke failed; inspect the report and map builder output.\n");
    exit(1);
}
