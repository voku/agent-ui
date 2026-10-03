<?php

declare(strict_types=1);

use voku\AgentLoop\ProjectLayout;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentSession\SessionStore;
use voku\AgentUi\Integration\AgentLoop\WorkflowProjectionGateway;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const CARD_COUNT = 85;
const SESSION_COUNT = 279;
const SEARCH_PADDING_BYTES = 155 * 1024 * 1024;

$mode = $argv[1] ?? '';
$root = $argv[2] ?? sys_get_temp_dir() . '/agent-ui-workflow-projection-perf';

if ($mode === 'setup') {
    setupFixture($root);
    exit(0);
}

if (!in_array($mode, ['single', 'batch'], true)) {
    fwrite(STDERR, "Usage: php tools/perf/workflow-projection-benchmark.php setup|single|batch [fixture-root]\n");
    exit(2);
}

$result = measure($root, $mode);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";

/**
 * @return array{mode: string, cards: int, sessions: int, search_bytes: int, elapsed_ms: float, sha256: string, peak_memory_bytes: int}
 */
function measure(string $root, string $mode): array
{
    $ids = [];
    for ($i = 1; $i <= CARD_COUNT; ++$i) {
        $ids[] = 'PERF-' . $i;
    }

    $gateway = new WorkflowProjectionGateway($root);
    $started = hrtime(true);

    /** @var array<string, WorkflowSnapshot|null> $snapshots */
    $snapshots = [];
    if ($mode === 'single') {
        foreach ($ids as $id) {
            $snapshots[$id] = $gateway->task($id);
        }
    } else {
        $snapshots = $gateway->tasks($ids);
    }

    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    $payload = [];
    foreach ($ids as $id) {
        $snapshot = $snapshots[$id] ?? null;
        $payload[$id] = $snapshot === null ? null : snapshotPayload($snapshot);
    }

    $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $layout = new ProjectLayout($root);
    $searchDatabase = MapArtifactPaths::forProject($root, $layout->mapRoot())->searchDatabase();
    $searchBytes = filesize($searchDatabase);
    if (!is_int($searchBytes)) {
        throw new RuntimeException('Unable to measure Search database size: ' . $searchDatabase);
    }

    return [
        'mode' => $mode,
        'cards' => CARD_COUNT,
        'sessions' => SESSION_COUNT,
        'search_bytes' => $searchBytes,
        'elapsed_ms' => round($elapsedMs, 3),
        'sha256' => hash('sha256', $encoded),
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ];
}

/**
 * @return array<string, mixed>
 */
function snapshotPayload(WorkflowSnapshot $snapshot): array
{
    return [
        'task_id' => $snapshot->taskId,
        'run_id' => $snapshot->runId,
        'mode' => $snapshot->mode,
        'state' => $snapshot->state,
        'references' => $snapshot->references,
        'disagreements' => $snapshot->disagreements,
        'next_action' => $snapshot->nextAction,
        'next_action_kind' => $snapshot->nextActionKind,
    ];
}

function setupFixture(string $root): void
{
    removeDirectory($root);

    $layout = new ProjectLayout($root);
    $boardRoot = $layout->boardRoot();
    $mapRoot = $layout->mapRoot();
    $sessionsRoot = $layout->sessionsRoot();

    mkdirOrFail($root . '/src');
    mkdirOrFail($boardRoot . '/cards');
    mkdirOrFail($mapRoot);

    file_put_contents(
        $root . '/composer.json',
        json_encode([
            'name' => 'voku/agent-ui-performance-fixture',
            'autoload' => ['psr-4' => ['Perf\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    );
    file_put_contents(
        $root . '/src/Fixture.php',
        "<?php\n\ndeclare(strict_types=1);\n\nnamespace Perf;\n\nfinal class Fixture\n{\n    public function value(): string\n    {\n        return 'fixture';\n    }\n}\n",
    );

    runCommand('git -C ' . escapeshellarg($root) . ' init -q');
    runCommand('git -C ' . escapeshellarg($root) . ' config user.email benchmark@example.invalid');
    runCommand('git -C ' . escapeshellarg($root) . ' config user.name benchmark');
    runCommand('git -C ' . escapeshellarg($root) . ' add composer.json src');
    runCommand('git -C ' . escapeshellarg($root) . ' commit -qm fixture');

    file_put_contents(
        $boardRoot . '/board.md',
        "# Board Metadata\n\n- **Project prefix:** PERF\n",
    );
    for ($i = 1; $i <= CARD_COUNT; ++$i) {
        $id = 'PERF-' . $i;
        file_put_contents(
            $boardRoot . '/cards/' . $id . '.md',
            "# {$id}: Performance fixture\n\n"
            . "- **Ticket:** {$id}\n"
            . "- **Lane:** BACKLOG\n"
            . "- **Status:** todo\n\n"
            . "## Agent Task Brief\n\nSynthetic workflow projection performance fixture.\n",
        );
    }

    $artifacts = MapArtifactPaths::forProject($root, $mapRoot);
    $repoRoot = dirname(__DIR__, 2);
    $agentMap = $repoRoot . '/vendor/bin/agent-map';
    if (!is_file($agentMap)) {
        throw new RuntimeException('agent-map Composer binary is missing: ' . $agentMap);
    }

    runCommand(
        escapeshellarg($agentMap)
        . ' build --root=' . escapeshellarg($root)
        . ' --paths=src --backend=structural --out=' . escapeshellarg($artifacts->indexJson()),
    );
    runCommand(
        escapeshellarg($agentMap)
        . ' search-index build --root=' . escapeshellarg($root)
        . ' --index=' . escapeshellarg($artifacts->indexJson())
        . ' --database=' . escapeshellarg($artifacts->searchDatabase()),
    );

    $pdo = new PDO('sqlite:' . $artifacts->searchDatabase(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('CREATE TABLE perf_padding (data BLOB NOT NULL)');
    $statement = $pdo->prepare('INSERT INTO perf_padding (data) VALUES (zeroblob(:bytes))');
    $statement->bindValue(':bytes', SEARCH_PADDING_BYTES, PDO::PARAM_INT);
    $statement->execute();
    unset($statement, $pdo);
    clearstatcache(true, $artifacts->searchDatabase());

    $store = new SessionStore();
    for ($i = 1; $i <= SESSION_COUNT; ++$i) {
        $store->create($sessionsRoot, 'NOISE-' . $i, 'perf-' . $i, 'benchmark');
    }

    $searchBytes = filesize($artifacts->searchDatabase());
    if (!is_int($searchBytes) || $searchBytes < 150 * 1024 * 1024) {
        throw new RuntimeException('Search database fixture is unexpectedly small.');
    }

    echo json_encode([
        'fixture_root' => $root,
        'cards' => CARD_COUNT,
        'sessions' => SESSION_COUNT,
        'search_bytes' => $searchBytes,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
}

function mkdirOrFail(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function runCommand(string $command): void
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Command failed ({$exitCode}): {$command}\n" . implode("\n", $output),
        );
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $path . '/' . $entry;
        if (is_dir($full) && !is_link($full)) {
            removeDirectory($full);
        } elseif (!unlink($full)) {
            throw new RuntimeException('Unable to remove file: ' . $full);
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Unable to remove directory: ' . $path);
    }
}
