<?php

declare(strict_types=1);

namespace voku\AgentUi\View;

use RuntimeException;

/**
 * The single owner of the one piece of JavaScript this control plane ships.
 *
 * The layout inlines the script and {@see \voku\AgentUi\Http\Response} allows
 * it through the Content-Security-Policy by hash. Both answers come from here
 * because they were once spelled independently, and the policy silently
 * refused to execute the script on every page: the Copy buttons rendered,
 * focused and did nothing at all.
 */
final class ClientScript
{
    private static ?string $code = null;

    /** The inline script body, without its surrounding `<script>` element. */
    public static function code(): string
    {
        if (self::$code === null) {
            $code = file_get_contents(__DIR__ . '/client.js');
            if ($code === false) {
                throw new RuntimeException('Unable to read the bundled client script.');
            }
            self::$code = $code;
        }

        return self::$code;
    }

    /** The exact `script-src` source expression that permits {@see code()}. */
    public static function cspSource(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::code(), true)) . "'";
    }
}
