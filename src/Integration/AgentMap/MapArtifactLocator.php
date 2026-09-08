<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentMap;

use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\MapArtifactPaths;

/**
 * Resolves where this project's agent-map artifacts live and reads the canonical index.
 *
 * Two gateways need the same answer to "which map is this repository's map", and
 * two copies of that answer is how a UI ends up searching one index while
 * describing another. The resolution rule itself stays agent-map's and
 * agent-loop's: `MapArtifactPaths` owns every filename below the root, and
 * `ProjectLayout` owns the governed root. The only decision made here is the
 * one the embedder is allowed to make - preferring a repository-local
 * `.agent-map/` directory when the developer built one there.
 */
final readonly class MapArtifactLocator
{
    public string $projectRoot;
    public MapArtifactPaths $paths;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $layout = new ProjectLayout($this->projectRoot);
        $mapRoot = is_dir($this->projectRoot . '/.agent-map')
            ? '.agent-map'
            : $layout->mapRoot();
        $this->paths = MapArtifactPaths::forProject($this->projectRoot, $mapRoot);
    }

    /** The index file that actually exists, JSON first, or null when the map was never built. */
    public function existingIndexPath(): ?string
    {
        if (is_file($this->paths->indexJson())) {
            return $this->paths->indexJson();
        }

        if (is_file($this->paths->indexToon())) {
            return $this->paths->indexToon();
        }

        return null;
    }

    /** A map that cannot be read is reported as absent; guessing at half an index is worse than none. */
    public function loadIndex(): ?AgentMapIndex
    {
        foreach ([$this->paths->indexJson(), $this->paths->indexToon()] as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            try {
                return (new IndexReader())->read($candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
