<?php

declare(strict_types=1);

use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

$autoload = getenv('AGENT_UI_COMPOSER_AUTOLOAD');
if (!is_string($autoload) || !is_file($autoload)) {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
}
if (!is_file($autoload)) {
    http_response_code(500);
    echo 'Composer autoloader was not found for agent-ui.';
    exit(1);
}

require $autoload;

// DNS-rebinding guard: the control plane only answers for loopback host names
// (plus AGENT_UI_ALLOWED_HOSTS, a comma-separated operator allowlist), and a
// cross-origin browser POST is refused.
$hostHeader = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$hostName = preg_replace('/:\d+$/', '', $hostHeader) ?? '';
$allowedHosts = ['localhost', '127.0.0.1', '[::1]'];
$extraHosts = getenv('AGENT_UI_ALLOWED_HOSTS');
if (is_string($extraHosts) && $extraHosts !== '') {
    foreach (explode(',', strtolower($extraHosts)) as $extraHost) {
        $allowedHosts[] = trim($extraHost);
    }
}
if (!in_array($hostName, $allowedHosts, true)) {
    http_response_code(421);
    echo 'Unexpected Host header for agent-ui.';
    exit(1);
}
// Use trusted server context; do not infer the external scheme from forwarded request headers.
$httpsSetting = $_SERVER['HTTPS'] ?? null;
$requestScheme = is_string($httpsSetting) && $httpsSetting !== '' && strtolower($httpsSetting) !== 'off'
    ? 'https'
    : 'http';
$normalizeAuthority = static function (array $parts, string $scheme): ?array {
    if (!in_array($scheme, ['http', 'https'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['path'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        return null;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '') {
        return null;
    }

    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    if (!is_int($port) || $port < 1 || $port > 65_535) {
        return null;
    }

    return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
};
$requestAuthority = parse_url($requestScheme . '://' . $hostHeader);
$requestOrigin = is_array($requestAuthority) ? $normalizeAuthority($requestAuthority, $requestScheme) : null;
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if (array_key_exists('HTTP_ORIGIN', $_SERVER)) {
    $originParts = is_string($origin) ? parse_url($origin) : false;
    $originScheme = is_array($originParts) ? strtolower((string) ($originParts['scheme'] ?? '')) : '';
    $originTuple = is_array($originParts) ? $normalizeAuthority($originParts, $originScheme) : null;

    if ($requestOrigin === null || $originTuple === null || $originTuple !== $requestOrigin) {
        http_response_code(403);
        echo 'Cross-origin request refused by agent-ui.';
        exit(1);
    }
}

ini_set('session.use_strict_mode', '1');
session_name('agent_ui');
$https = $_SERVER['HTTPS'] ?? null;
session_set_cookie_params([
    'httponly' => true,
    'secure' => is_string($https) && $https !== '' && strtolower($https) !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    http_response_code(500);
    echo 'Unable to start the local agent-ui session.';
    exit(1);
}

$projectRoot = getenv('AGENT_UI_PROJECT_ROOT');
if (!is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = getcwd();
}
if (!is_string($projectRoot) || !is_dir($projectRoot)) {
    http_response_code(500);
    echo 'AGENT_UI_PROJECT_ROOT must point to an existing project directory.';
    exit(1);
}

(new Application($projectRoot, dirname(__DIR__) . '/templates'))
    ->handle(Request::fromGlobals())
    ->send();
