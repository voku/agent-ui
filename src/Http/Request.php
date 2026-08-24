<?php

declare(strict_types=1);

namespace voku\AgentUi\Http;

final readonly class Request
{
    /** @param array<string, string> $query */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $methodValue = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = strtoupper(is_string($methodValue) ? $methodValue : 'GET');
        $uriValue = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = is_string($uriValue) ? $uriValue : '/';
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $query = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $query[$key] = $value;
            }
        }

        return new self($method, $path, $query);
    }
}
