<?php

declare(strict_types=1);

namespace voku\AgentUi\Http;

use voku\AgentUi\View\ClientScript;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body,
        public int $status = 200,
        public array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function redirect(string $location): self
    {
        return new self('', 303, ['Location' => $location]);
    }

    /**
     * The policy the shipped pages are actually allowed to run under.
     *
     * `script-src` names the bundled enhancement script by hash rather than
     * `'unsafe-inline'`, so the one script this control plane ships executes
     * and anything injected into a page still does not.
     */
    public static function contentSecurityPolicy(): string
    {
        return "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src "
            . ClientScript::cspSource()
            . "; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Content-Security-Policy: ' . self::contentSecurityPolicy());
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
