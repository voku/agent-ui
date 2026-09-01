<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Feature\Setup\SetupAction;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Integration\AgentLoop\RepositorySetupGateway;
use voku\AgentUi\Security\CsrfTokenManager;
use voku\AgentUi\View\TemplateRenderer;

/**
 * Removing managed assets is the one setup operation a mis-click cannot undo,
 * and it used to be a plain submit button rendered directly beneath the
 * benign install action.
 */
final class SetupRemovalConfirmationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnUnconfirmedRemovalIsRefusedBeforeAnyOwnerCall(): void
    {
        $csrf = new CsrfTokenManager();
        $action = new SetupAction(
            new RepositorySetupGateway(sys_get_temp_dir()),
            $csrf,
            new TemplateRenderer(__DIR__ . '/../../templates'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Removal was not confirmed');

        $action->mutate('setup_remove', 'claude', new Request('POST', '/setup/claude/remove', [], [
            '_csrf' => $csrf->token(),
            'plan_id' => 'whatever',
            'expected_state' => 'whatever',
        ]));
    }
}
