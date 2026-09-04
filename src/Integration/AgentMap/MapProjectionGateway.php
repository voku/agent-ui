<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentMap\Context\EditContextPlan;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;

final readonly class MapProjectionGateway
{
    private string $projectRoot;
    private MapArtifactPaths $paths;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $layout = new ProjectLayout($this->projectRoot);
        $mapRoot = is_dir($this->projectRoot . '/.agent-map')
            ? '.agent-map'
            : $layout->mapRoot();
        $this->paths = MapArtifactPaths::forProject($this->projectRoot, $mapRoot);
    }

    public function readiness(): MapReadinessSnapshot
    {
        try {
            $inspector = new MapReadinessInspector();
            $readiness = $inspector->inspect($this->paths);

            $index = $this->loadIndex();

            $classesCount = 0;
            $methodsCount = 0;
            $functionsCount = 0;
            $totalSymbols = 0;

            if ($index !== null) {
                foreach ($index->files as $file) {
                    foreach ($file->symbols as $sym) {
                        $totalSymbols++;
                        if ($sym->kind === 'function') {
                            $functionsCount++;
                        } else {
                            $classesCount++;
                            $methodsCount += count($sym->methods);
                        }
                    }
                }
            }

            $format = is_file($this->paths->indexToon()) ? 'toon' : 'json';
            $path = is_file($this->paths->indexToon()) ? $this->paths->indexToon() : $this->paths->indexJson();

            /** @var list<array{path: string, reason: string}> $staleEntries */
            $staleEntries = $readiness->staleEntries;

            return new MapReadinessSnapshot(
                status: $readiness->mapState,
                backend: $index !== null ? $index->backend : 'unknown',
                format: $format,
                path: $path,
                snapshot: $readiness->mapSnapshot,
                staleEntries: $staleEntries,
                fileCount: $index !== null ? count($index->files) : 0,
                relationCount: $index !== null ? count($index->relations) : 0,
                diagnosticCount: $index !== null ? count($index->diagnostics) : 0,
                symbolCount: $totalSymbols,
                classCount: $classesCount,
                methodCount: $methodsCount,
                functionCount: $functionsCount,
                failure: $readiness->mapFailure,
            );
        } catch (Throwable $e) {
            return new MapReadinessSnapshot(
                status: 'invalid',
                backend: 'unknown',
                format: 'none',
                path: $this->paths->indexJson(),
                failure: $e->getMessage(),
            );
        }
    }

    /**
     * @return list<MapSymbolSummary>
     */
    public function search(string $query, int $limit = 40): array
    {
        $index = $this->loadIndex();
        if ($index === null) {
            return [];
        }

        $queryMatch = $index->query($query);
        $results = [];
        $count = 0;

        foreach ($queryMatch->files as $file) {
            foreach ($file->symbols as $symbol) {
                $incoming = count($index->incoming($symbol->id(), 'calls'));
                $outgoing = count($index->outgoing($symbol->id(), 'calls'));
                $results[] = new MapSymbolSummary(
                    id: $symbol->id(),
                    kind: $symbol->kind,
                    name: $symbol->name,
                    fqn: $symbol->fqn,
                    file: $file->path,
                    lineStart: $symbol->lineStart,
                    lineEnd: $symbol->lineEnd,
                    parameters: $symbol->displayParameters(),
                    returnType: $symbol->displayReturnType(),
                    methodCount: count($symbol->methods),
                    callerCount: $incoming,
                    calleeCount: $outgoing,
                );
                $count++;
                if ($count >= $limit) {
                    break 2;
                }
            }
        }

        return $results;
    }

    public function symbol(string $id): ?MapSymbolDetail
    {
        $index = $this->loadIndex();
        if ($index === null) {
            return null;
        }

        $isMethod = str_starts_with($id, 'method:') || str_contains($id, '::');
        if ($isMethod) {
            $normalizedMethod = str_starts_with($id, 'method:') ? substr($id, 7) : $id;
            try {
                $resolved = $index->resolveMethod($normalizedMethod);
                $incoming = [];
                foreach ($index->incoming($resolved->id, 'calls') as $rel) {
                    $incoming[] = [
                        'sourceId' => $rel->sourceId,
                        'file' => $rel->file,
                        'line' => $rel->lineStart,
                        'kind' => $rel->kind,
                    ];
                }
                $outgoing = [];
                foreach ($index->outgoing($resolved->id, 'calls') as $rel) {
                    foreach ($rel->targetIds as $tid) {
                        $outgoing[] = [
                            'targetId' => $tid,
                            'file' => $rel->file,
                            'line' => $rel->lineStart,
                            'kind' => $rel->kind,
                        ];
                    }
                }

                return new MapSymbolDetail(
                    id: $resolved->id,
                    kind: 'method',
                    name: $resolved->method->name,
                    fqn: $resolved->owner->fqn . '::' . $resolved->method->name,
                    file: $resolved->file->path,
                    lineStart: $resolved->method->lineStart,
                    lineEnd: $resolved->method->lineEnd,
                    parameters: $resolved->method->displayParameters(),
                    returnType: $resolved->method->displayReturnType(),
                    extends: [],
                    implements: [],
                    uses: [],
                    methods: [],
                    callers: $incoming,
                    callees: $outgoing,
                );
            } catch (Throwable) {
                // fall through to symbol search
            }
        }

        $cleanId = preg_replace('/^[a-z]+:/', '', $id) ?? $id;
        foreach ($index->files as $file) {
            foreach ($file->symbols as $sym) {
                if ($sym->id() === $id || ltrim($sym->fqn, '\\') === ltrim($cleanId, '\\') || $sym->name === $cleanId) {
                    $incoming = [];
                    foreach ($index->incoming($sym->id(), null) as $rel) {
                        $incoming[] = [
                            'sourceId' => $rel->sourceId,
                            'file' => $rel->file,
                            'line' => $rel->lineStart,
                            'kind' => $rel->kind,
                        ];
                    }
                    $outgoing = [];
                    foreach ($index->outgoing($sym->id(), null) as $rel) {
                        foreach ($rel->targetIds as $tid) {
                            $outgoing[] = [
                                'targetId' => $tid,
                                'file' => $rel->file,
                                'line' => $rel->lineStart,
                                'kind' => $rel->kind,
                            ];
                        }
                    }

                    $methods = [];
                    foreach ($sym->methods as $m) {
                        $methods[] = [
                            'name' => $m->name,
                            'visibility' => $m->visibility,
                            'lineStart' => $m->lineStart,
                            'lineEnd' => $m->lineEnd,
                            'parameters' => $m->displayParameters(),
                            'returnType' => $m->displayReturnType(),
                            'id' => $sym->methodId($m),
                        ];
                    }

                    return new MapSymbolDetail(
                        id: $sym->id(),
                        kind: $sym->kind,
                        name: $sym->name,
                        fqn: $sym->fqn,
                        file: $file->path,
                        lineStart: $sym->lineStart,
                        lineEnd: $sym->lineEnd,
                        parameters: $sym->displayParameters(),
                        returnType: $sym->displayReturnType(),
                        extends: $sym->extends,
                        implements: $sym->implements,
                        uses: $sym->uses,
                        methods: $methods,
                        callers: $incoming,
                        callees: $outgoing,
                    );
                }
            }
        }

        return null;
    }

    public function editContext(string $target): ?EditContextPlan
    {
        $index = $this->loadIndex();
        if ($index === null) {
            return null;
        }

        try {
            $cleanTarget = str_starts_with($target, 'method:') ? substr($target, 7) : $target;
            $planner = new EditContextPlanner();
            $policy = new EditContextPolicy(maximumFiles: 8, maximumSourceBytes: 32000);

            return $planner->plan($index, $cleanTarget, $policy);
        } catch (Throwable) {
            return null;
        }
    }

    private function loadIndex(): ?AgentMapIndex
    {
        if (is_file($this->paths->indexJson())) {
            try {
                return (new IndexReader())->read($this->paths->indexJson());
            } catch (Throwable) {
                // ignore
            }
        }

        if (is_file($this->paths->indexToon())) {
            try {
                return (new IndexReader())->read($this->paths->indexToon());
            } catch (Throwable) {
                // ignore
            }
        }

        return null;
    }
}
