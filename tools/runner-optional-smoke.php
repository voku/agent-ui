<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use RuntimeException;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoopRunner\Application\RunnerControlService;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentKanban\CardMutationGateway;
use voku\AgentUi\Integration\AgentLoopRunner\RunnerGateway;

require dirname(__DIR__) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['absent', 'installed'], true)) {
    fwrite(STDERR, "Usage: php tools/runner-optional-smoke.php absent|installed\n");
    exit(2);
}

$root = sys_get_temp_dir() . '/agent-ui-runner-matrix-' . bin2hex(random_bytes(6));
$taskId = 'UI-24';

try {
    mkdir($root . '/.agent-loop/todo/cards', 0o775, true);
    mkdir($root . '/.agent-loop/learning', 0o775, true);
    mkdir($root . '/src', 0o775, true);
    file_put_contents($root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
    file_put_contents($root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");

    (new CardMutationGateway($root))->create(
        cardId: $taskId,
        title: 'Prove optional tagged Runner integration',
        lane: 'BACKLOG',
        status: 'todo',
        summary: 'Runner must remain optional and observational.',
        taskBrief: 'Exercise agent-ui with and without the tagged Runner package.',
        validation: 'composer ci',
    );

    git($root, ['init', '-q']);
    git($root, ['config', 'user.email', 'runner-matrix@example.test']);
    git($root, ['config', 'user.name', 'Runner Matrix']);
    git($root, ['add', '.']);
    git($root, ['commit', '-qm', 'fixture']);
    $baseCommit = trim(git($root, ['rev-parse', 'HEAD']));

    silent(static fn (): int => (new WorkflowPlanCommand($root))->run([
        $taskId,
        '--by', 'agent-ui-matrix',
        '--file', 'src/Foo.php',
        '--goal', 'Prove optional tagged Runner integration.',
        '--validation', 'composer ci',
        '--base-commit', $baseCommit,
    ]));
    silent(static fn (): int => (new WorkflowApproveCommand($root))->run([$taskId, '--by', 'agent-ui-matrix']));
    silent(static fn (): int => (new WorkflowExecutionProfileCommand($root))->run([
        $taskId,
        '--profile', 'surgical',
        '--by', 'agent-ui-matrix',
    ]));

    $enter = new HostFrontDoorCommand(
        $root,
        static function (array $argv) use ($root, $taskId): int {
            $directory = $root . '/.agent-loop/recall/' . $taskId;
            mkdir($directory, 0o775, true);
            file_put_contents($directory . '/meta.json', json_encode([
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'compilation_id' => 'runner-optional-matrix',
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($directory . '/system.md', "# Recall\nStay governed.\n");

            return 0;
        },
    );
    silent(static fn (): int => $enter->run('enter', [$taskId, '--format=json']));

    $application = new Application($root, dirname(__DIR__) . '/templates');
    foreach ([
        '/' => 'Developer Cockpit',
        '/setup' => null,
        '/board' => null,
        '/knowledge' => null,
        '/task/' . $taskId => 'Managed runner',
        '/task/' . $taskId . '/context' => null,
        '/task/' . $taskId . '/work' => null,
        '/task/' . $taskId . '/evidence' => null,
    ] as $path => $expectedText) {
        $response = $application->handle(new Request('GET', $path));
        ensure($response->status === 200, $path . ' returned HTTP ' . $response->status . '.');
        if ($expectedText !== null) {
            ensure(str_contains($response->body, $expectedText), $path . ' did not render expected UI text: ' . $expectedText);
        }
    }

    $runner = new RunnerGateway($root);
    $taskResponse = $application->handle(new Request('GET', '/task/' . $taskId));

    if ($mode === 'absent') {
        ensure(!class_exists(RunnerControlService::class), 'Runner class unexpectedly exists in the --no-dev consumer.');
        ensure(!$runner->isInstalled(), 'RunnerGateway reported Runner installed in the absent shape.');
        $snapshot = $runner->status($taskId);
        ensure(!$snapshot->installed, 'Absent Runner must project installed=false.');
        ensure(!$snapshot->allowRun && !$snapshot->allowResume && !$snapshot->allowCancel, 'Absent Runner exposed managed controls.');
        ensure(str_contains($taskResponse->body, 'is optional and is not installed'), 'Task UI did not render explicit Runner-unavailable state.');
        echo "runner-optional-matrix: absent shape OK\n";
        exit(0);
    }

    ensure(class_exists(RunnerControlService::class), 'Tagged Runner is missing from the installed shape.');
    ensure($runner->isInstalled(), 'RunnerGateway did not detect the installed tagged Runner.');

    $prettyVersion = InstalledVersions::getPrettyVersion('voku/agent-loop-runner');
    ensure(is_string($prettyVersion) && $prettyVersion !== '' && !str_contains($prettyVersion, 'dev'), 'Runner did not resolve to a tagged release.');

    $ownerStatus = (new RunnerControlService($root))->status($taskId);
    $snapshot = $runner->status($taskId);
    ensure($snapshot->installed, 'Installed Runner must project installed=true.');
    ensure($snapshot->profile === $ownerStatus->authority->profile->value, 'UI Runner profile drifted from Loop authority.');
    ensure($snapshot->currentStageId === $ownerStatus->authority->currentStageId, 'UI Runner stage drifted from Loop authority.');
    ensure($snapshot->currentAttempt === $ownerStatus->authority->currentAttempt, 'UI Runner attempt drifted from Loop authority.');
    ensure($snapshot->complete === $ownerStatus->authority->complete(), 'UI Runner completion drifted from Loop authority.');
    ensure($snapshot->observationStatus === null && $snapshot->hostId === null, 'Fresh installed shape invented Runner observation state.');
    ensure($snapshot->allowRun === $ownerStatus->allows('run'), 'UI run control drifted from Runner owner status.');
    ensure($snapshot->allowResume === $ownerStatus->allows('resume'), 'UI resume control drifted from Runner owner status.');
    ensure($snapshot->allowCancel === $ownerStatus->allows('cancel'), 'UI cancel control drifted from Runner owner status.');
    ensure(str_contains($taskResponse->body, 'Loop authority'), 'Task UI no longer labels Loop authority separately from Runner observation.');

    echo 'runner-optional-matrix: installed shape OK (' . $prettyVersion . ")\n";
} finally {
    removeDirectory($root);
}

/** @param list<string> $arguments */
function git(string $root, array $arguments): string
{
    $process = proc_open(
        ['git', '-C', $root, ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start git.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('git failed: ' . trim((string) $stderr));
    }

    return (string) $stdout;
}

/** @param callable(): int $operation */
function silent(callable $operation): void
{
    ob_start();
    try {
        $exit = $operation();
    } finally {
        ob_end_clean();
    }
    ensure($exit === 0, 'Owner fixture command failed with exit ' . $exit . '.');
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
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
            continue;
        }
        unlink($full);
    }
    rmdir($path);
}
