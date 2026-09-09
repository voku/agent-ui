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

    /** The map root this locator chose, repository-relative. */
    public string $mapRoot;

    public MapArtifactPaths $paths;

    /**
     * Every map root this embedder considers, in preference order.
     *
     * @var list<string>
     */
    private array $candidateRoots;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $layout = new ProjectLayout($this->projectRoot);
        $this->candidateRoots = array_values(array_unique(['.agent-map', $layout->mapRoot()]));
        $this->mapRoot = is_dir($this->projectRoot . '/.agent-map')
            ? '.agent-map'
            : $layout->mapRoot();
        $this->paths = MapArtifactPaths::forProject($this->projectRoot, $this->mapRoot);
    }

    /**
     * Index files under the candidate roots this locator did not choose.
     *
     * A repository that built a map twice - once through agent-map directly and
     * once through the governed lifecycle - carries two indexes that can
     * disagree, and a reader who refreshed the other one cannot tell from a
     * page that reports only the chosen path. Naming the files that were not
     * read is an observation about this checkout, not a second resolution
     * rule: which root wins is still decided in the constructor above and is
     * not reconsidered here.
     *
     * @return list<string>
     */
    public function unreadIndexPaths(): array
    {
        $chosen = [$this->paths->indexJson(), $this->paths->indexToon()];
        $unread = [];
        foreach ($this->candidateRoots as $root) {
            $paths = MapArtifactPaths::forProject($this->projectRoot, $root);
            foreach ([$paths->indexJson(), $paths->indexToon()] as $candidate) {
                // Compare resolved files, not root spellings: a project that
                // configures `map_root` to the repository-local directory names
                // the same index twice, and that is one index, not two.
                if (in_array($candidate, $chosen, true) || in_array($candidate, $unread, true)) {
                    continue;
                }

                if (is_file($candidate)) {
                    $unread[] = $candidate;
                }
            }
        }

        return $unread;
    }

    /**
     * The index that was read, together with the file it came from.
     *
     * The two answers come out of one read on purpose. Asking separately meant
     * parsing twice, and a refresh landing between the two would have let the
     * counts describe one file while the reported provenance named another -
     * the exact confusion this pairing exists to remove.
     *
     * `existingIndexPath()` still answers where an index file sits; this
     * answers which one was read. They differ when the preferred file exists
     * but cannot be decoded.
     *
     * @return array{path: string, index: AgentMapIndex}|null
     */
    public function readIndex(): ?array
    {
        foreach ([$this->paths->indexJson(), $this->paths->indexToon()] as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            try {
                return ['path' => $candidate, 'index' => (new IndexReader())->read($candidate)];
            } catch (Throwable) {
                continue;
            }
        }

        return null;
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
        return $this->readIndex()['index'] ?? null;
    }
}
