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

    private static ?string $graphLibrary = null;

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

    /**
     * The bundled graph library, inlined only by the page that draws a graph.
     *
     * It is ~370 KB. Prepended to {@see code()} it rode along on every page of
     * the control plane although only the workflow progress view uses it.
     */
    public static function graphLibrary(): string
    {
        if (self::$graphLibrary === null) {
            $path = __DIR__ . '/vendor/cytoscape.min.js';
            $library = is_file($path) ? file_get_contents($path) : '';
            self::$graphLibrary = is_string($library) ? $library : '';
        }

        return self::$graphLibrary;
    }

    /** The exact `script-src` source expressions that permit the shipped scripts. */
    public static function cspSource(): string
    {
        $sources = [self::hashSource(self::code())];
        if (self::graphLibrary() !== '') {
            $sources[] = self::hashSource(self::graphLibrary());
        }

        return implode(' ', $sources);
    }

    private static function hashSource(string $script): string
    {
        return "'sha256-" . base64_encode(hash('sha256', $script, true)) . "'";
    }
}
