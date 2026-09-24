<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Security\CsrfTokenManager;

/**
 * Dream is reached through agent-loop's WorkflowDreamService, never by
 * rebuilding agent-learning's evaluation or parsing its CLI report. Viewing
 * writes nothing; writing needs a valid token and an explicit confirmation.
 */
final class DreamPageTest extends TestCase
{
    private string $root;
    private string $learningRoot;
    private string $templates;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-dream-' . bin2hex(random_bytes(6));
        $this->learningRoot = $this->root . '/.agent-loop/learning';
        $this->templates = dirname(__DIR__, 2) . '/templates';
        if (!mkdir($this->learningRoot . '/findings/validated', 0o775, true) || !mkdir($this->root . '/src', 0o775, true)) {
            throw new RuntimeException('Unable to create the Learning fixture root.');
        }
        file_put_contents($this->root . '/src/Example.php', "<?php\n");
        // One validated lesson on two independent tasks: the smallest corpus
        // agent-learning's Dream turns into a promotion candidate.
        $this->writeFinding('finding.2026-06-01.001', 'APP-1');
        $this->writeFinding('finding.2026-06-02.001', 'APP-2');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testViewingDreamShowsTheOwnerDecisionsAndWritesNothing(): void
    {
        $response = (new Application($this->root, $this->templates))->handle(new Request('GET', '/knowledge/dream'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('PROMOTION_CANDIDATE', $response->body);
        self::assertStringContainsString('finding.2026-06-01.001', $response->body);
        self::assertStringContainsString('name="confirm_write"', $response->body);
        self::assertSame([], $this->candidates());
    }

    public function testWritingCandidatesNeedsATokenAndAnExplicitConfirmation(): void
    {
        $app = new Application($this->root, $this->templates);
        $csrf = (new CsrfTokenManager())->token();

        $forged = $app->handle(new Request('POST', '/knowledge/dream', body: ['confirm_write' => 'write']));
        $unconfirmed = $app->handle(new Request('POST', '/knowledge/dream', body: ['_csrf' => $csrf]));

        self::assertSame(400, $forged->status);
        self::assertSame(400, $unconfirmed->status);
        self::assertSame([], $this->candidates());
    }

    public function testConfirmedWriteRecordsCandidatesOnlyAndTheNextRunSuppressesThem(): void
    {
        $app = new Application($this->root, $this->templates);
        $csrf = (new CsrfTokenManager())->token();

        $written = $app->handle(new Request('POST', '/knowledge/dream', body: ['_csrf' => $csrf, 'confirm_write' => 'write']));

        self::assertSame(303, $written->status);
        self::assertSame('/knowledge?tab=proposals', $written->headers['Location'] ?? null);
        self::assertNotSame([], $this->candidates());
        self::assertSame([], glob($this->learningRoot . '/proposals/approved/*.json') ?: [], 'Dream output is never approved guidance');

        $again = $app->handle(new Request('GET', '/knowledge/dream'));
        self::assertStringContainsString('Dream found nothing new to review.', $again->body);
    }

    /** @return list<string> */
    private function candidates(): array
    {
        return glob($this->learningRoot . '/proposals/candidate/*.json') ?: [];
    }

    private function writeFinding(string $id, string $taskId): void
    {
        file_put_contents($this->learningRoot . '/findings/validated/' . $id . '.json', json_encode([
            'id' => $id,
            'task_id' => $taskId,
            'session' => 'session.' . $taskId,
            'created_at' => '2026-06-01T00:00:00+00:00',
            'created_by' => 'tester',
            'scope' => ['src'],
            'observation' => 'Observation is concrete.',
            'evidence' => [['type' => 'file_reference', 'path' => 'src/Example.php', 'line' => 1]],
            'hypothesis' => 'Hypothesis is distinct.',
            'validated_conclusion' => 'Conclusion is validated.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'pattern_key' => 'ui.dream',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
}
