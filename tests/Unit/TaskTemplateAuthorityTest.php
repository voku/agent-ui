<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TaskTemplateAuthorityTest extends TestCase
{
    public function testTaskFlowDoesNotAttributeHumanDecisionsToAgentLoop(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/task/index.php');
        if (!is_string($template)) {
            throw new RuntimeException('Unable to read task template.');
        }

        self::assertStringContainsString(
            'human approval, review acknowledgement and Learning decisions remain human authority',
            $template,
        );
        self::assertStringNotContainsString(
            'agent-loop owns workflow, approval, validation, review and Learning',
            $template,
        );
    }
}
