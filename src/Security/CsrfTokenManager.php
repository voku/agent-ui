<?php

declare(strict_types=1);

namespace voku\AgentUi\Security;

use InvalidArgumentException;

final readonly class CsrfTokenManager
{
    private const string SESSION_KEY = '_agent_ui_csrf';

    public function token(): string
    {
        $existing = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($existing) && preg_match('/^[a-f0-9]{64}$/', $existing) === 1) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    public function assertValid(?string $token): void
    {
        $current = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || !is_string($current) || !hash_equals($current, $token)) {
            throw new InvalidArgumentException('Invalid CSRF token.');
        }
    }
}
