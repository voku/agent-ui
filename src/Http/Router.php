<?php

declare(strict_types=1);

namespace voku\AgentUi\Http;

use InvalidArgumentException;

final readonly class Router
{
    /** @return array{route: 'home'|'board'|'task'|'evidence'|'handoff'|'approve'|'review_ack'|'learning', task_id?: string} */
    public function match(Request $request): array
    {
        if ($request->method === 'GET') {
            if ($request->path === '/') {
                return ['route' => 'home'];
            }
            if ($request->path === '/board') {
                return ['route' => 'board'];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)$#')) !== null) {
                return ['route' => 'task', 'task_id' => $taskId];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/evidence$#')) !== null) {
                return ['route' => 'evidence', 'task_id' => $taskId];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/handoff$#')) !== null) {
                return ['route' => 'handoff', 'task_id' => $taskId];
            }
        }

        if ($request->method === 'POST') {
            foreach ([
                'approve' => '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/approve$#',
                'review_ack' => '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/review-ack$#',
                'learning' => '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/learning$#',
            ] as $route => $pattern) {
                $taskId = $this->taskId($request->path, $pattern);
                if ($taskId !== null) {
                    return ['route' => $route, 'task_id' => $taskId];
                }
            }
        }

        throw new InvalidArgumentException('Route not found.');
    }

    private function taskId(string $path, string $pattern): ?string
    {
        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }
}
