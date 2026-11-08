<?php

declare(strict_types=1);

// A real owner-backed candidate Contract for the browser refresh check.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use voku\AgentLoop\Workflow\TaskContractStore;

$root = $argv[1] ?? throw new RuntimeException('project root required');
mkdir($root . '/.agent-loop/todo/cards', 0o775, true);
file_put_contents($root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
foreach (['UI-36', 'UI-37'] as $taskId) {
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
