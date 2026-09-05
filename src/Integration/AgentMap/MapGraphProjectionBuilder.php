<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

use InvalidArgumentException;
use voku\AgentMap\Discovery\ArchitectureMapBuilder;
use voku\AgentMap\Discovery\ArchitectureMapReport;
use voku\AgentMap\Discovery\ArchitectureRegion;
use voku\AgentMap\Discovery\FileCouplingGraphBuilder;
use voku\AgentMap\Discovery\WeightedFileGraph;
use voku\AgentMap\Index\AgentMapIndex;

final readonly class MapGraphProjectionBuilder
{
    public function build(
        AgentMapIndex $index,
        ?string $regionQuery = null,
        int $maximumNodes = 30,
        int $maximumEdges = 80,
    ): MapGraphSnapshot {
        if ($maximumNodes < 2) {
            throw new InvalidArgumentException('Map graph maximumNodes must be at least 2.');
        }
        if ($maximumEdges < 1) {
            throw new InvalidArgumentException('Map graph maximumEdges must be at least 1.');
        }

        $graph = (new FileCouplingGraphBuilder())->build($index);
        $architecture = (new ArchitectureMapBuilder())->build($index);
        $regionQuery = trim((string) $regionQuery);

        if ($regionQuery !== '') {
            return $this->fileSnapshot(
                $graph,
                $architecture,
                $architecture->resolveRegion($regionQuery),
                $maximumNodes,
                $maximumEdges,
            );
        }

        $finestRegions = [];
        foreach ($architecture->regions as $region) {
            if ($region->level === 1) {
                $finestRegions[] = $region;
            }
        }

        if (count($finestRegions) >= 2) {
            return $this->architectureSnapshot(
                $graph,
                $architecture,
                $finestRegions,
                $maximumNodes,
                $maximumEdges,
            );
        }

        return $this->fileSnapshot($graph, $architecture, null, $maximumNodes, $maximumEdges);
    }

    /**
     * @param list<ArchitectureRegion> $regions
     */
    private function architectureSnapshot(
        WeightedFileGraph $graph,
        ArchitectureMapReport $architecture,
        array $regions,
        int $maximumNodes,
        int $maximumEdges,
    ): MapGraphSnapshot {
        /** @var array<string, ArchitectureRegion> $regionsById */
        $regionsById = [];
        /** @var array<string, string> $fileToRegion */
        $fileToRegion = [];
        foreach ($regions as $region) {
            $regionsById[$region->id] = $region;
            foreach ($region->files as $file) {
                $fileToRegion[$file] = $region->id;
            }
        }

        /** @var array<string, array{source: string, target: string, weight: float, signals: array<string, float>}> $aggregated */
        $aggregated = [];
        /** @var array<string, float> $nodeWeights */
        $nodeWeights = array_fill_keys(array_keys($regionsById), 0.0);

        foreach ($graph->adjacency as $left => $neighbours) {
            foreach ($neighbours as $right => $weight) {
                if (strcmp($left, $right) >= 0) {
                    continue;
                }

                $sourceRegion = $fileToRegion[$left] ?? null;
                $targetRegion = $fileToRegion[$right] ?? null;
                if ($sourceRegion === null || $targetRegion === null || $sourceRegion === $targetRegion) {
                    continue;
                }

                [$source, $target] = strcmp($sourceRegion, $targetRegion) < 0
                    ? [$sourceRegion, $targetRegion]
                    : [$targetRegion, $sourceRegion];
                $key = $source . "\0" . $target;
                $entry = $aggregated[$key] ?? [
                    'source' => $source,
                    'target' => $target,
                    'weight' => 0.0,
                    'signals' => [],
                ];
                $entry['weight'] += $weight;
                foreach ($graph->signalsBetween($left, $right) as $signal => $signalWeight) {
                    $entry['signals'][$signal] = ($entry['signals'][$signal] ?? 0.0) + $signalWeight;
                }
                $aggregated[$key] = $entry;
                $nodeWeights[$source] = ($nodeWeights[$source] ?? 0.0) + $weight;
                $nodeWeights[$target] = ($nodeWeights[$target] ?? 0.0) + $weight;
            }
        }

        $nodes = [];
        foreach ($regions as $region) {
            $nodes[] = new MapGraphNode(
                id: $region->id,
                label: $region->label,
                kind: $region->kind,
                weight: $nodeWeights[$region->id] ?? 0.0,
                fileCount: count($region->files),
                regionId: $region->id,
            );
        }
        usort($nodes, static fn (MapGraphNode $left, MapGraphNode $right): int =>
            $right->weight <=> $left->weight
            ?: $right->fileCount <=> $left->fileCount
            ?: $left->label <=> $right->label
            ?: $left->id <=> $right->id);

        $totalNodeCount = count($nodes);
        $nodes = array_slice($nodes, 0, $maximumNodes);
        $selected = array_fill_keys(array_map(static fn (MapGraphNode $node): string => $node->id, $nodes), true);

        $edges = [];
        foreach ($aggregated as $entry) {
            if (!isset($selected[$entry['source']], $selected[$entry['target']])) {
                continue;
            }
            $edges[] = new MapGraphEdge(
                sourceId: $entry['source'],
                targetId: $entry['target'],
                weight: $entry['weight'],
                signals: $this->sortedSignals($entry['signals']),
            );
        }
        usort($edges, static fn (MapGraphEdge $left, MapGraphEdge $right): int =>
            $right->weight <=> $left->weight
            ?: $left->sourceId <=> $right->sourceId
            ?: $left->targetId <=> $right->targetId);

        $totalEdgeCount = count($aggregated);
        $edges = array_slice($edges, 0, $maximumEdges);

        return new MapGraphSnapshot(
            mapDigest: $architecture->mapDigest,
            scope: 'architecture',
            title: 'Architecture regions',
            nodes: $nodes,
            edges: $edges,
            totalNodeCount: $totalNodeCount,
            totalEdgeCount: $totalEdgeCount,
        );
    }

    private function fileSnapshot(
        WeightedFileGraph $graph,
        ArchitectureMapReport $architecture,
        ?ArchitectureRegion $region,
        int $maximumNodes,
        int $maximumEdges,
    ): MapGraphSnapshot {
        $files = $region?->files ?? $graph->files;
        $fileSet = array_fill_keys($files, true);

        /** @var array<string, float> $nodeWeights */
        $nodeWeights = array_fill_keys($files, 0.0);
        /** @var list<array{source: string, target: string, weight: float, signals: array<string, float>}> $allEdges */
        $allEdges = [];

        foreach ($files as $left) {
            foreach ($graph->neighbours($left) as $right => $weight) {
                if (!isset($fileSet[$right]) || strcmp($left, $right) >= 0) {
                    continue;
                }

                $nodeWeights[$left] += $weight;
                $nodeWeights[$right] = ($nodeWeights[$right] ?? 0.0) + $weight;
                $allEdges[] = [
                    'source' => $left,
                    'target' => $right,
                    'weight' => $weight,
                    'signals' => $this->sortedSignals($graph->signalsBetween($left, $right)),
                ];
            }
        }

        $nodes = [];
        foreach ($files as $file) {
            $nodes[] = new MapGraphNode(
                id: $file,
                label: basename(str_replace('\\', '/', $file)),
                kind: 'file',
                weight: $nodeWeights[$file] ?? 0.0,
                file: $file,
                regionId: $region?->id,
            );
        }
        usort($nodes, static fn (MapGraphNode $left, MapGraphNode $right): int =>
            $right->weight <=> $left->weight
            ?: $left->label <=> $right->label
            ?: $left->id <=> $right->id);

        $totalNodeCount = count($nodes);
        $nodes = array_slice($nodes, 0, $maximumNodes);
        $selected = array_fill_keys(array_map(static fn (MapGraphNode $node): string => $node->id, $nodes), true);

        $edges = [];
        foreach ($allEdges as $entry) {
            if (!isset($selected[$entry['source']], $selected[$entry['target']])) {
                continue;
            }
            $edges[] = new MapGraphEdge(
                sourceId: $entry['source'],
                targetId: $entry['target'],
                weight: $entry['weight'],
                signals: $entry['signals'],
            );
        }
        usort($edges, static fn (MapGraphEdge $left, MapGraphEdge $right): int =>
            $right->weight <=> $left->weight
            ?: $left->sourceId <=> $right->sourceId
            ?: $left->targetId <=> $right->targetId);
        $edges = array_slice($edges, 0, $maximumEdges);

        $breadcrumbs = [];
        if ($region !== null) {
            $path = array_reverse($architecture->pathForRegion($region));
            foreach ($path as $pathRegion) {
                $breadcrumbs[] = ['id' => $pathRegion->id, 'label' => $pathRegion->label];
            }
        }

        return new MapGraphSnapshot(
            mapDigest: $architecture->mapDigest,
            scope: $region === null ? 'files' : 'region',
            title: $region === null ? 'File coupling overview' : $region->label . ' · file coupling',
            nodes: $nodes,
            edges: $edges,
            totalNodeCount: $totalNodeCount,
            totalEdgeCount: count($allEdges),
            breadcrumbs: $breadcrumbs,
            regionId: $region?->id,
            regionLabel: $region?->label,
        );
    }

    /**
     * @param array<string, float> $signals
     * @return array<string, float>
     */
    private function sortedSignals(array $signals): array
    {
        arsort($signals, SORT_NUMERIC);

        return $signals;
    }
}
