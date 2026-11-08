<?php

declare(strict_types=1);

// Run with Composer's production dependency graph: no agent-loop-runner.
// The real Loop CLI owns every lifecycle answer; agent-ui only projects it.

use Composer\InstalledVersions;
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
    foreach (array_keys($payload) as $key) {
        if (!is_string($key)) {
            throw new RuntimeException('Loop returned a lifecycle envelope with non-string keys.');
        }
    }

    /** @var array<string, mixed> $payload */
    return $payload;
}

function requireCondition(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureCsrfToken(): string
{
    $token = $_SESSION['_agent_ui_csrf'] ?? null;
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Missing UI CSRF token.');
    }

    return $token;
}

/**
 * Read the public owner projection included in the lifecycle envelope.
 *
 * @param array<string, mixed> $payload
 * @return array{owner: string, state: string, observation_mode: string, gate: string, validation_failed: bool, implementation_snapshot: string}
 */
function verificationProjection(array $payload): array
{
    $manifest = $payload['manifest'] ?? null;
    $references = is_array($manifest) ? ($manifest['references'] ?? null) : null;
    $verification = is_array($references) ? ($references['verification'] ?? null) : null;
    if (!is_array($verification)
        || !is_string($verification['owner'] ?? null)
        || !is_string($verification['state'] ?? null)
        || !is_string($verification['observation_mode'] ?? null)
        || !is_string($verification['gate'] ?? null)
        || !is_bool($verification['validation_failed'] ?? null)
        || !is_string($verification['implementation_snapshot'] ?? null)
        || preg_match('/^sha256:[0-9a-f]{64}$/D', $verification['implementation_snapshot']) !== 1) {
        throw new RuntimeException('Loop returned an unavailable or invalid verification projection.');
    }

    return [
        'owner' => $verification['owner'],
        'state' => $verification['state'],
        'observation_mode' => $verification['observation_mode'],
        'gate' => $verification['gate'],
        'validation_failed' => $verification['validation_failed'],
        'implementation_snapshot' => $verification['implementation_snapshot'],
    ];
}

$package = dirname(__DIR__, 2);
$loopBinary = $package . '/vendor/bin/agent-loop';
$root = sys_get_temp_dir() . '/agent-ui-native-loop-' . bin2hex(random_bytes(8));
$reportFile = $package . '/var/host-native-no-runner.json';

requireCondition(is_file($loopBinary), 'Real agent-loop CLI executable is missing.');
requireCondition(class_exists(InstalledVersions::class) && !InstalledVersions::isInstalled('voku/agent-loop-runner'),
    'The no-Runner fixture requires a production dependency graph without agent-loop-runner.');
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

$csrf = fixtureCsrfToken();
$approved = $application->handle(new Request('POST', '/task/UI-36/approve', body: [
    '_csrf' => $csrf,
    'actor' => 'ci-fixture-reviewer',
    'revision' => '1',
    'return_to' => 'progress',
]));
requireCondition($approved->status === 303, 'Typed human-decision service did not record fixture approval.');

$afterApproval = loopAction($loopBinary, $root, 'enter');
requireCondition($afterApproval['mutation_ready'] === true && $afterApproval['next_action_kind'] === 'host_work',
    'The fixture-approved Contract did not reach host work.');
$state = (new \voku\AgentUi\Integration\AgentLoop\WorkflowProgressGateway($root))->task('UI-36');
requireCondition($state->taskId === 'UI-36', 'Progress projection lost task identity.');

// Deliberately fail actual repository validation before implementing the
// approved README change. Loop must return host work, not fake completion.
$failedValidation = loopAction($loopBinary, $root, 'finish');
$failedEvidence = verificationProjection($failedValidation);
requireCondition($failedValidation['complete'] === false, 'Failed validation cannot complete the governed task.');
requireCondition($failedValidation['next_action_kind'] === 'host_work', 'Failed validation must route back to host work.');
requireCondition($failedEvidence['owner'] === 'agent-loop' && $failedEvidence['observation_mode'] === 'checked'
    && $failedEvidence['state'] === 'blocked' && $failedEvidence['gate'] === 'validation'
    && $failedEvidence['validation_failed'], 'Owner did not report a checked validation failure.');

// A repeated finish against the same failing implementation must not mark
// validation passed or advance to a review merely because it was retried.
$repeatedFailure = loopAction($loopBinary, $root, 'finish');
$repeatedEvidence = verificationProjection($repeatedFailure);
requireCondition($repeatedFailure['complete'] === false, 'Repeated failure must not complete the task.');
requireCondition($repeatedFailure['next_action_kind'] === 'host_work', 'Repeating an unchanged failure must not advance.');
requireCondition($repeatedEvidence['gate'] === 'validation' && $repeatedEvidence['validation_failed']
    && hash_equals($failedEvidence['implementation_snapshot'], $repeatedEvidence['implementation_snapshot']),
    'The unchanged retry did not retain the failed implementation snapshot.');

// Apply one ordinary in-scope host-native change, then re-enter the real
// Loop reconciliation. This exercises the failed -> repaired evidence path.
file_put_contents($root . '/README.md', "# Isolated owner-backed task\\n\\nIMPLEMENTED\\n");
$repairedValidation = loopAction($loopBinary, $root, 'finish');
$repairedEvidence = verificationProjection($repairedValidation);
requireCondition($repairedValidation['complete'] === false, 'Review/learning gates must not be silently skipped.');
requireCondition($repairedEvidence['owner'] === 'agent-loop' && $repairedEvidence['observation_mode'] === 'checked'
    && $repairedEvidence['gate'] === 'execution_review' && !$repairedEvidence['validation_failed']
    && !hash_equals($failedEvidence['implementation_snapshot'], $repairedEvidence['implementation_snapshot']),
    'The repaired snapshot did not advance to the owner-reported review gate.');

$report = [
    'schema_version' => 1,
    'test' => 'host-native-loop-without-runner',
    'runner_installed' => false,
    'task_id' => 'UI-36',
    'before_plan' => ['next_action_kind' => $beforePlan['next_action_kind'], 'mutation_ready' => $beforePlan['mutation_ready']],
    'candidate' => ['next_action_kind' => $beforeApproval['next_action_kind'], 'mutation_ready' => $beforeApproval['mutation_ready']],
    'after_approval' => ['next_action_kind' => $afterApproval['next_action_kind'], 'mutation_ready' => $afterApproval['mutation_ready']],
    'validation_failed' => ['next_action_kind' => $failedValidation['next_action_kind'], 'complete' => $failedValidation['complete'], 'verification' => $failedEvidence],
    'unchanged_retry' => ['next_action_kind' => $repeatedFailure['next_action_kind'], 'complete' => $repeatedFailure['complete'], 'verification' => $repeatedEvidence],
    'validation_repaired' => ['next_action_kind' => $repairedValidation['next_action_kind'], 'complete' => $repairedValidation['complete'], 'verification' => $repairedEvidence],
    'ui_progress_state' => $state->state,
    'completed_governed_task' => false,
    'real_authenticated_agent' => false,
];
if (!is_dir(dirname($reportFile))) {
    mkdir(dirname($reportFile), 0755, true);
}
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
