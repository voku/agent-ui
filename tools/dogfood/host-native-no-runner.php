<?php

declare(strict_types=1);

// Run with Composer's production dependency graph: no agent-loop-runner.
// The real Loop CLI owns every lifecycle answer; agent-ui only projects it.

use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @param list<string> $command
 * @param list<int> $acceptedExitCodes
 */
function runCommand(array $command, string $directory, array $acceptedExitCodes = [0]): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot execute ' . $command[0]);
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if (!in_array($exit, $acceptedExitCodes, true) || !is_string($stdout)) {
        throw new RuntimeException(sprintf(
            'Command failed with exit %d: %s; stderr: %s; stdout: %s',
            $exit,
            implode(' ', $command),
            is_string($stderr) ? $stderr : '(unavailable)',
            is_string($stdout) ? $stdout : '(unavailable)',
        ));
    }

    return $stdout;
}

/**
 * @return array<string, mixed>
 */
function loopAction(string $binary, string $root, string $verb): array
{
    $payload = json_decode(runCommand([PHP_BINARY, $binary, $verb, 'UI-36', '--format=json'], $root, [0, 1]), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || !is_string($payload['next_action_kind'] ?? null)
        || ($verb === 'enter' && !is_bool($payload['mutation_ready'] ?? null))
        || ($verb === 'finish' && !is_bool($payload['complete'] ?? null))) {
        throw new RuntimeException('Loop returned an invalid ' . $verb . ' lifecycle envelope.');
    }

    return $payload;
}

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$package = dirname(__DIR__, 2);
$loopBinary = $package . '/vendor/bin/agent-loop';
$root = sys_get_temp_dir() . '/agent-ui-native-loop-' . bin2hex(random_bytes(8));
$reportFile = $package . '/var/host-native-no-runner.json';

requireCondition(is_file($loopBinary), 'Real agent-loop CLI executable is missing.');
requireCondition(mkdir($root . '/.agent-loop/todo/cards', 0700, true), 'Could not create isolated test project.');
file_put_contents($root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** UI\n");
file_put_contents($root . '/.agent-loop/todo/cards/UI-36.md', "# UI-36: Native lifecycle check\n\n- **Lane:** BACKLOG\n- **Status:** todo\n\n## Summary\n\nReview the owner-backed lifecycle.\n");
file_put_contents($root . '/README.md', "# Isolated owner-backed task\n");
runCommand(['git', 'init', '-q'], $root);
runCommand(['git', 'config', 'user.name', 'Test Agent'], $root);
runCommand(['git', 'config', 'user.email', 'test-agent@example.invalid'], $root);
runCommand(['git', 'add', '.'], $root);
runCommand(['git', 'commit', '-qm', 'test: isolated task baseline'], $root);

$beforePlan = loopAction($loopBinary, $root, 'enter');
requireCondition($beforePlan['mutation_ready'] === false, 'Unplanned task unexpectedly permits implementation.');
requireCondition($beforePlan['next_action_kind'] !== 'none', 'Unplanned task unexpectedly has no next action.');

(new TaskContractStore($root))->create(
    'UI-36',
    'Verify that host-native Loop remains authoritative without the optional Runner.',
    ['README.md'],
    ['No automatic approval'],
    ['grep -q IMPLEMENTED README.md'],
    'ci-planner',
    acceptanceCriteria: ['Approval precedes implementation.'],
);
$beforeApproval = loopAction($loopBinary, $root, 'enter');
requireCondition($beforeApproval['mutation_ready'] === false, 'Candidate Contract unexpectedly authorizes implementation.');
requireCondition($beforeApproval['next_action_kind'] !== 'none', 'Candidate Contract unexpectedly has no action.');

$_SESSION = [];
$application = new Application($root, $package . '/templates');
$page = $application->handle(new Request('GET', '/task/UI-36/progress'));
requireCondition($page->status === 200, 'Workflow cockpit could not display the candidate task.');
requireCondition(str_contains($page->body, 'Approve revision 1'), 'Cockpit does not show exact Contract revision.');

$csrf = $_SESSION['_agent_ui_csrf'] ?? null;
requireCondition(is_string($csrf), 'Missing UI CSRF token.');
$approved = $application->handle(new Request('POST', '/task/UI-36/approve', body: [
    '_csrf' => $csrf,
    'actor' => 'ci-fixture-reviewer',
    'revision' => '1',
    'return_to' => 'progress',
]));
requireCondition($approved->status === 303, 'Typed human-decision service did not record fixture approval.');

$afterApproval = loopAction($loopBinary, $root, 'enter');
requireCondition(is_string($afterApproval['next_action_kind']), 'Owner returned no post-approval action.');
$state = (new \voku\AgentUi\Integration\AgentLoop\WorkflowProgressGateway($root))->task('UI-36');
requireCondition($state->taskId === 'UI-36', 'Progress projection lost task identity.');

// Deliberately fail actual repository validation before implementing the
// approved README change. Loop must return host work, not fake completion.
$failedValidation = loopAction($loopBinary, $root, 'finish');
requireCondition($failedValidation['complete'] === false, 'Failed validation cannot complete the governed task.');
requireCondition($failedValidation['next_action_kind'] === 'host_work', 'Failed validation must route back to host work.');

// A repeated finish against the same failing implementation must not mark
// validation passed or advance to a review merely because it was retried.
$repeatedFailure = loopAction($loopBinary, $root, 'finish');
requireCondition($repeatedFailure['complete'] === false, 'Repeated failure must not complete the task.');
requireCondition($repeatedFailure['next_action_kind'] === 'host_work', 'Repeating an unchanged failure must not advance.');

// Apply one ordinary in-scope host-native change, then re-enter the real
// Loop reconciliation. This exercises the failed -> repaired evidence path.
file_put_contents($root . '/README.md', "# Isolated owner-backed task\\n\\nIMPLEMENTED\\n");
$repairedValidation = loopAction($loopBinary, $root, 'finish');
requireCondition($repairedValidation['complete'] === false, 'Review/learning gates must not be silently skipped.');
requireCondition($repairedValidation['next_action_kind'] !== 'host_work',
    'After a genuine in-scope fix, validation must no longer report the previous failure.');

$report = [
    'schema_version' => 1,
    'test' => 'host-native-loop-without-runner',
    'runner_installed' => false,
    'task_id' => 'UI-36',
    'before_plan' => ['next_action_kind' => $beforePlan['next_action_kind'], 'mutation_ready' => $beforePlan['mutation_ready']],
    'candidate' => ['next_action_kind' => $beforeApproval['next_action_kind'], 'mutation_ready' => $beforeApproval['mutation_ready']],
    'after_approval' => ['next_action_kind' => $afterApproval['next_action_kind'], 'mutation_ready' => $afterApproval['mutation_ready']],
    'validation_failed' => ['next_action_kind' => $failedValidation['next_action_kind'], 'complete' => $failedValidation['complete']],
    'unchanged_retry' => ['next_action_kind' => $repeatedFailure['next_action_kind'], 'complete' => $repeatedFailure['complete']],
    'validation_repaired' => ['next_action_kind' => $repairedValidation['next_action_kind'], 'complete' => $repairedValidation['complete']],
    'ui_progress_state' => $state->state,
    'completed_governed_task' => false,
    'real_authenticated_agent' => false,
];
if (!is_dir(dirname($reportFile))) {
    mkdir(dirname($reportFile), 0755, true);
}
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
