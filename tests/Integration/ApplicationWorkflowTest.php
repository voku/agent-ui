<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Security\CsrfTokenManager;

final class ApplicationWorkflowTest extends TestCase
{
    private string $root;
    private string $templates;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-app-' . bin2hex(random_bytes(6));
        $this->templates = dirname(__DIR__, 2) . '/templates';

        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true)) {
            throw new RuntimeException('Unable to create board fixture root.');
        }
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }

    public function testEndToEndCardCreationAndContractApproval(): void
    {
        $app = new Application($this->root, $this->templates);
        $csrf = (new CsrfTokenManager())->token();

        // 1. GET /board/new renders create form
        $newFormResponse = $app->handle(new Request('GET', '/board/new'));
        self::assertSame(200, $newFormResponse->status);
        self::assertStringContainsString('Create Kanban Card', $newFormResponse->body);
        self::assertStringContainsString('APP-1', $newFormResponse->body);

        // 2. POST /board/new creates card APP-1
        $createResponse = $app->handle(new Request('POST', '/board/new', body: [
            '_csrf' => $csrf,
            'card_id' => 'APP-1',
            'title' => 'Build login system',
            'lane' => 'BACKLOG',
            'status' => 'todo',
            'summary' => 'Support secure user login',
            'task_brief' => 'Detailed brief for building login.',
            'validation' => 'composer test',
            'priority' => '2',
            'assignee' => 'developer',
        ]));
        self::assertSame(303, $createResponse->status);
        self::assertSame('/task/APP-1', $createResponse->headers['Location']);
        self::assertFileExists($this->root . '/.agent-loop/todo/cards/APP-1.md');

        // 3. GET /task/APP-1 shows task page with Edit button and quick transition
        $taskResponse = $app->handle(new Request('GET', '/task/APP-1'));
        self::assertSame(200, $taskResponse->status);
        self::assertStringContainsString('Build login system', $taskResponse->body);
        self::assertStringContainsString('Edit card', $taskResponse->body);
        self::assertStringContainsString('Move to READY', $taskResponse->body);
        self::assertStringContainsString('No Contract', $taskResponse->body);

        // 4. GET /task/APP-1/edit renders edit form
        $editResponse = $app->handle(new Request('GET', '/task/APP-1/edit'));
        self::assertSame(200, $editResponse->status);
        self::assertStringContainsString('Edit Card: Build login system', $editResponse->body);
        self::assertStringContainsString('Build login system', $editResponse->body);

        // 5. POST /task/APP-1/move moves card to READY
        $moveResponse = $app->handle(new Request('POST', '/task/APP-1/move', body: [
            '_csrf' => $csrf,
            'target_lane' => 'READY',
            'actor' => 'developer',
        ]));
        self::assertSame(303, $moveResponse->status);
        self::assertSame('/task/APP-1', $moveResponse->headers['Location']);

        // 6. GET /task/APP-1/contract renders contract form
        $contractViewResponse = $app->handle(new Request('GET', '/task/APP-1/contract'));
        self::assertSame(200, $contractViewResponse->status);
        self::assertStringContainsString('Propose Contract', $contractViewResponse->body);

        // 7. POST /task/APP-1/contract proposes a contract
        $proposeResponse = $app->handle(new Request('POST', '/task/APP-1/contract', body: [
            '_csrf' => $csrf,
            'contract_action' => 'propose',
            'goal' => 'Implement secure authentication module',
            'scope' => "src/Auth/\ntests/Auth/",
            'non_goals' => "Social login\nOAuth1",
            'validation' => "composer test\ncomposer run phpstan",
            'acceptance_criteria' => 'Users can log in with password',
            'planned_by' => 'architect',
        ]));
        self::assertSame(303, $proposeResponse->status);
        self::assertSame('/task/APP-1', $proposeResponse->headers['Location']);

        // 8. GET /task/APP-1 now shows candidate contract awaiting approval
        $taskCandidateResponse = $app->handle(new Request('GET', '/task/APP-1'));
        self::assertSame(200, $taskCandidateResponse->status);
        self::assertStringContainsString('Contract Revision 1 (Candidate)', $taskCandidateResponse->body);
        self::assertStringContainsString('Approve the current Contract', $taskCandidateResponse->body);
        self::assertStringContainsString('Implement secure authentication module', $taskCandidateResponse->body);
        self::assertStringContainsString('src/Auth/', $taskCandidateResponse->body);

        // 9. POST /task/APP-1/approve approves the contract
        $approveResponse = $app->handle(new Request('POST', '/task/APP-1/approve', body: [
            '_csrf' => $csrf,
            'actor' => 'lead-engineer',
        ]));
        self::assertSame(303, $approveResponse->status);
        self::assertSame('/task/APP-1', $approveResponse->headers['Location']);

        // 10. GET /task/APP-1 now shows contract is approved!
        $taskApprovedResponse = $app->handle(new Request('GET', '/task/APP-1'));
        self::assertSame(200, $taskApprovedResponse->status);
        self::assertStringContainsString('Contract Approved', $taskApprovedResponse->body);
        self::assertStringContainsString('Approved by <strong>lead-engineer</strong>', $taskApprovedResponse->body);
    }
}
