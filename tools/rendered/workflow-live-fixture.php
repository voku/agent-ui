<?php

declare(strict_types=1);

// A real owner-backed candidate Contract for the browser refresh check.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;

$root = $argv[1] ?? throw new RuntimeException('project root required');
mkdir($root . '/.agent-loop/todo/cards', 0o775, true);
file_put_contents($root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
foreach (['UI-36', 'UI-37', 'UI-38', 'UI-39'] as $taskId) {
    file_put_contents(
        $root . '/.agent-loop/todo/cards/' . $taskId . '.md',
        '# ' . $taskId . ": Live workflow fixture\n\n- **Lane:** BACKLOG\n- **Status:** todo\n\n"
        . "## Summary\n\nA task for the live workflow browser check.\n\n"
        . "## Agent Task Brief\n\nKeep workflow truth in agent-loop.\n",
    );
}
(new TaskContractStore($root))->create(
    'UI-36',
    'Keep live workflow progress owner-backed.',
    ['src/Feature/Task'],
    ['no inferred completion'],
    ['composer ci'],
    'browser-fixture',
);


// Additional real execution fixtures exercise Attention resolution in Chromium.
// Match the owner-backed PHP integration test rather than inventing UI state.
if (!mkdir($root . '/src', 0o775, true)) {
    throw new RuntimeException('Unable to create browser fixture source directory.');
}
if (!copy(dirname(__DIR__, 2) . '/composer.json', $root . '/composer.json')) {
    throw new RuntimeException('Unable to copy the browser fixture composer manifest.');
}
file_put_contents(
    $root . '/src/Worker.php',
    "<?php\nfinal class Worker { public function run(): string { return 'idle'; } }\n",
);

$git = 'git -C ' . escapeshellarg($root);
foreach ([
    $git . ' init -q',
    $git . ' config user.email browser@example.test',
    $git . ' config user.name browser-fixture',
    $git . ' add -A',
    $git . ' commit -q -m browser-fixture',
] as $command) {
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Browser fixture Git setup failed: ' . implode("\n", $output));
    }
}

$baseCommit = [];
exec($git . ' rev-parse HEAD 2>&1', $baseCommit, $status);
if ($status !== 0 || !isset($baseCommit[0])) {
    throw new RuntimeException('Unable to read browser fixture base commit.');
}

foreach (['UI-38', 'UI-39'] as $taskId) {
    $contracts = new TaskContractStore($root);
    $contracts->create(
        taskId: $taskId,
        goal: 'Exercise human Attention resolution in the browser.',
        scope: ['src/Worker.php'],
        nonGoals: ['no production changes'],
        validation: ['php -l src/Worker.php'],
        plannedBy: 'browser-fixture',
        baseCommit: trim($baseCommit[0]),
    );
    $contract = $contracts->load($taskId);
    $contracts->approve($taskId, 'browser-maintainer', $contract->revision);

    ob_start();
    try {
        $status = (new HostFrontDoorApplication($root))->run('enter', [$taskId, '--format=json']);
    } finally {
        ob_end_clean();
    }
    if ($status !== 0) {
        throw new RuntimeException('Owner enter failed for browser fixture ' . $taskId);
    }

    $execution = new ExecutionGateway($root);
    $stageId = $execution->projection($taskId)->currentStageId ?? 'investigate';
    $bundle = $execution->prepareStage($taskId, $stageId);
    $execution->submitStageResult(new StageResult(
        submissionId: 'sub:' . bin2hex(random_bytes(6)),
        taskId: $taskId,
        runId: $bundle->runId,
        contractRevision: $bundle->contractRevision,
        executionPlanDigest: $bundle->executionPlanDigest,
        stageId: $stageId,
        attempt: 1,
        outcome: StageOutcome::NEEDS_CLARIFICATION,
        candidateRevision: $bundle->candidateRevision,
        artifactReferences: [],
        validationReferences: [],
        summary: 'Browser fixture needs a human clarification.',
    ));

    if ($execution->projection($taskId)->attention === null) {
        throw new RuntimeException('Owner did not produce pending Attention for ' . $taskId);
    }
}
