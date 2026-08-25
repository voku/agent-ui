<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentUi\Integration\AgentLoop\AuditTrailGateway;

final class AuditTrailGatewayTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-ui-audit-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testAdaptsPublicOwnerReportWithoutParsingOwnerFiles(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'TASK-1',
            'Make audit facts readable.',
            ['src/Foo.php'],
            [],
            ['php -l src/Foo.php'],
            'planner',
        );
        $contracts->approve('TASK-1', 'approver');

        $audit = (new AuditTrailGateway($this->root))->task('TASK-1');

        self::assertSame('TASK-1', $audit->taskId);
        self::assertSame('approved', $audit->contractStatus);
        self::assertSame(1, $audit->contractRevision);
        self::assertSame('approver', $audit->approvalBy);
        self::assertCount(1, $audit->validation);
        self::assertSame('missing', $audit->validation[0]->status);
        self::assertSame('Contract approved', $audit->timeline[0]->title);
        self::assertSame('missing', $audit->reviewStatus);
        self::assertNull($audit->learningDecision);
    }
}
