<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Security\CsrfTokenManager;

final class CsrfTokenManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsStableForTheCurrentSessionAndValidates(): void
    {
        $csrf = new CsrfTokenManager();
        $token = $csrf->token();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $csrf->token());
        $csrf->assertValid($token);
        self::addToAssertionCount(1);
    }

    public function testMissingTokenFailsClosed(): void
    {
        (new CsrfTokenManager())->token();
        $this->expectException(InvalidArgumentException::class);
        (new CsrfTokenManager())->assertValid(null);
    }

    public function testDifferentTokenFailsClosed(): void
    {
        (new CsrfTokenManager())->token();
        $this->expectException(InvalidArgumentException::class);
        (new CsrfTokenManager())->assertValid(str_repeat('0', 64));
    }
}
