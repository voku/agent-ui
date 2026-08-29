<?php

declare(strict_types=1);

namespace voku\AgentUi\Http;

/**
 * A one-shot outcome notice carried across a POST-redirect-GET.
 *
 * Owner mutations used to redirect in complete silence, so a recorded
 * approval and a request that changed nothing looked identical. This says
 * only what the UI itself did — it is never rendered as owner state, and the
 * page it lands on still reads every fact from the owning package.
 */
final readonly class FlashNotice
{
    private const string SESSION_KEY = '_agent_ui_notice';

    public function record(string $message): void
    {
        if (!isset($_SESSION)) {
            return;
        }

        $_SESSION[self::SESSION_KEY] = $message;
    }

    /** Reads and clears the pending notice, so a reload does not repeat it. */
    public function take(): ?string
    {
        if (!isset($_SESSION)) {
            return null;
        }

        $message = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        return is_string($message) && $message !== '' ? $message : null;
    }
}
