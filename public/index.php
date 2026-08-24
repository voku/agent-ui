<?php

declare(strict_types=1);

use voku\AgentUi\Application\Application;
use voku\AgentUi\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

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
