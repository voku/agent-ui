<?php

declare(strict_types=1);

namespace voku\AgentUi\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $body
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
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

        return new self($method, $path, self::stringValues($_GET), self::stringValues($_POST));
    }

    /** @param array<array-key, mixed> $values @return array<string, string> */
    private static function stringValues(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
