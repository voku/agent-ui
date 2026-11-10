<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\View\ClientScript;

final class GitHubWorkflowPageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-gh-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o700, true)) {
            throw new RuntimeException('Cannot create isolated project fixture.');
        }
    }

    protected function tearDown(): void
    {
        rmdir($this->root);
    }

    public function testReadOnlyPageRendersWithoutWritingProjectFilesOrQueryingGitHub(): void
    {
        $before = scandir($this->root);
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');
        $response = $app->handle(new Request('GET', '/github-workflow'));

        self::assertSame(200, $response->status);
        self::assertSame($before, scandir($this->root));
        self::assertStringContainsString('Cross-repository work', $response->body);
        self::assertStringContainsString('id="gh-import"', $response->body);
        self::assertStringContainsString('id="gh-export"', $response->body);
        self::assertStringContainsString('id="gh-compare"', $response->body);
        self::assertStringContainsString('GitHub observation', $response->body);
    }

    public function testHeadAndPostPreserveReadOnlyBoundary(): void
    {
        $app = new Application($this->root, dirname(__DIR__, 2) . '/templates');

        self::assertSame('', $app->handle(new Request('HEAD', '/github-workflow'))->body);
        self::assertSame(400, $app->handle(new Request('POST', '/github-workflow'))->status);
    }

    public function testGithubEnhancementMatchesCspHashAndRestrictsNetworkDestination(): void
    {
        $script = ClientScript::githubWorkflowCode();
        self::assertStringContainsString('updateFromGitHub', $script);
        self::assertStringContainsString('proofs: existing ? existing.proofs : []', $script);
        self::assertStringContainsString('threads: sameHead ? existing.threads : null', $script);
        self::assertStringContainsString("return 'mixed';", $script);
        self::assertStringContainsString('credentials: \'omit\'', $script);
        self::assertStringContainsString(
            "'sha256-" . base64_encode(hash('sha256', $script, true)) . "'",
            Response::contentSecurityPolicy(),
        );
        self::assertStringContainsString("connect-src 'self' https://api.github.com", Response::contentSecurityPolicy());
        self::assertStringNotContainsString('agent-loop-runner', $script);
    }
}
