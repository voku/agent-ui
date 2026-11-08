<?php

declare(strict_types=1);

namespace voku\AgentUi\Runtime;

/** Validated browser origin for the optional, local terminal prototype. */
final class LocalTerminal
{
    public static function origin(): ?string
    {
        $value = getenv('AGENT_UI_TERMINAL_ORIGIN');
        if (!is_string($value) || preg_match('/^http:\/\/127\.0\.0\.1:([1-9][0-9]{0,4})$/D', $value, $matches) !== 1) {
            return null;
        }

        $port = (int) $matches[1];

        return $port >= 1024 && $port <= 65535 ? $value : null;
    }
}
