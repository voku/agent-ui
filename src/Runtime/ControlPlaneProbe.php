<?php

declare(strict_types=1);

namespace voku\AgentUi\Runtime;

use Closure;
use InvalidArgumentException;
use JsonException;

final readonly class ControlPlaneProbe
{
    /** @var Closure(string, int): array{http_status: int|null, body: string|null, error: string|null} */
    private Closure $transport;

    /**
     * @param (Closure(string, int): array{http_status: int|null, body: string|null, error: string|null})|null $transport
     */
    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport ?? self::networkTransport();
    }

    /**
     * @return array{
     *     status: 'ready'|'unreachable'|'wrong_service'|'wrong_project'|'invalid_response',
     *     url: string,
     *     project_id: string,
     *     detail: string|null
     * }
     */
    public function probe(string $host, int $port, ControlPlaneIdentity $expected): array
    {
        self::assertEndpoint($host, $port);
        $url = self::baseUrl($host, $port);
        $response = ($this->transport)($host, $port);

        if ($response['http_status'] === null) {
            return self::result('unreachable', $url, $expected->projectId, $response['error']);
        }

        if ($response['http_status'] !== 200) {
            return self::result(
                'wrong_service',
                $url,
                $expected->projectId,
                sprintf('Health endpoint returned HTTP %d.', $response['http_status']),
            );
        }

        if ($response['body'] === null) {
            return self::result('invalid_response', $url, $expected->projectId, 'Health endpoint returned no body.');
        }

        try {
            $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return self::result('invalid_response', $url, $expected->projectId, $exception->getMessage());
        }

        if (!is_array($payload)) {
            return self::result('invalid_response', $url, $expected->projectId, 'Health response is not a JSON object.');
        }

        if (($payload['service'] ?? null) !== ControlPlaneIdentity::SERVICE) {
            return self::result('wrong_service', $url, $expected->projectId, 'Endpoint is not agent-ui.');
        }

        if (($payload['schema'] ?? null) !== ControlPlaneIdentity::SCHEMA || ($payload['status'] ?? null) !== 'ready') {
            return self::result('invalid_response', $url, $expected->projectId, 'agent-ui health schema/status is unsupported.');
        }

        $projectId = $payload['project_id'] ?? null;
        if (!is_string($projectId) || $projectId === '') {
            return self::result('invalid_response', $url, $expected->projectId, 'agent-ui health response has no project identity.');
        }

        if (!hash_equals($expected->projectId, $projectId)) {
            return self::result('wrong_project', $url, $expected->projectId, 'agent-ui is serving a different project.');
        }

        return self::result('ready', $url, $expected->projectId, null);
    }

    private static function assertEndpoint(string $host, int $port): void
    {
        if ($host === '' || preg_match('/^[A-Za-z0-9.:-]+$/D', $host) !== 1) {
            throw new InvalidArgumentException('Host must be a plain local hostname or IP address.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port must be between 1 and 65535.');
        }
    }

    private static function baseUrl(string $host, int $port): string
    {
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return sprintf('http://%s:%d', $authority, $port);
    }

    /**
     * @param 'ready'|'unreachable'|'wrong_service'|'wrong_project'|'invalid_response' $status
     * @return array{
     *     status: 'ready'|'unreachable'|'wrong_service'|'wrong_project'|'invalid_response',
     *     url: string,
     *     project_id: string,
     *     detail: string|null
     * }
     */
    private static function result(string $status, string $url, string $projectId, ?string $detail): array
    {
        return [
            'status' => $status,
            'url' => $url,
            'project_id' => $projectId,
            'detail' => $detail,
        ];
    }

    /** @return Closure(string, int): array{http_status: int|null, body: string|null, error: string|null} */
    private static function networkTransport(): Closure
    {
        return static function (string $host, int $port): array {
            $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
            $warning = null;
            $errorCode = 0;
            $errorMessage = '';
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                $warning = $message;

                return true;
            });
            try {
                $socket = stream_socket_client(
                    sprintf('tcp://%s:%d', $authority, $port),
                    $errorCode,
                    $errorMessage,
                    1.0,
                    STREAM_CLIENT_CONNECT,
                );
            } finally {
                restore_error_handler();
            }

            if (!is_resource($socket)) {
                $detail = $errorMessage !== '' ? $errorMessage : $warning;

                return ['http_status' => null, 'body' => null, 'error' => $detail];
            }

            stream_set_timeout($socket, 1);
            $request = "GET /__agent-ui/health HTTP/1.1\r\n"
                . sprintf("Host: %s:%d\r\n", $authority, $port)
                . "Accept: application/json\r\nConnection: close\r\n\r\n";

            if (fwrite($socket, $request) === false) {
                fclose($socket);

                return ['http_status' => null, 'body' => null, 'error' => 'Unable to write the health probe request.'];
            }

            $raw = stream_get_contents($socket);
            fclose($socket);
            if (!is_string($raw)) {
                return ['http_status' => 0, 'body' => null, 'error' => 'Unable to read the health probe response.'];
            }

            $parts = explode("\r\n\r\n", $raw, 2);
            if (count($parts) !== 2 || preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/', $parts[0], $matches) !== 1) {
                return ['http_status' => 0, 'body' => $raw, 'error' => 'Malformed HTTP response.'];
            }

            return [
                'http_status' => (int) $matches[1],
                'body' => $parts[1],
                'error' => null,
            ];
        };
    }
}
