<?php

declare(strict_types=1);

namespace voku\AgentUi\Http;

use InvalidArgumentException;

final readonly class Router
{
    /** @return array{route: 'home'|'board'|'task'|'evidence', task_id?: string} */
    public function match(Request $request): array
    {
        if ($request->method !== 'GET') {
            throw new InvalidArgumentException('Read-only agent-ui v0.1 accepts GET requests only.');
        }

        if ($request->path === '/') {
            return ['route' => 'home'];
        }
        if ($request->path === '/board') {
            return ['route' => 'board'];
        }
        if (preg_match('#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)$#', $request->path, $matches) === 1) {
            return ['route' => 'task', 'task_id' => strtoupper($matches[1])];
        }
        if (preg_match('#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/evidence$#', $request->path, $matches) === 1) {
            return ['route' => 'evidence', 'task_id' => strtoupper($matches[1])];
        }

        throw new InvalidArgumentException('Route not found.');
    }
}
