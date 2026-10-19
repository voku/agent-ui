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
