<?php

declare(strict_types=1);

namespace voku\AgentUi\Http;

use InvalidArgumentException;

final readonly class Router
{
    /** @return array{route: 'home'|'board'|'knowledge'|'knowledge_finding'|'knowledge_proposal'|'knowledge_guidance'|'task'|'task_learning'|'context'|'work'|'evidence'|'history'|'handoff'|'approve'|'review_ack'|'learning'|'runner_run'|'runner_resume'|'runner_cancel', task_id?: string, knowledge_id?: string} */
    public function match(Request $request): array
    {
        if ($request->method === 'GET') {
            if ($request->path === '/') {
                return ['route' => 'home'];
            }
            if ($request->path === '/board') {
                return ['route' => 'board'];
            }
            if ($request->path === '/knowledge') {
                return ['route' => 'knowledge'];
            }
            foreach ([
                'knowledge_finding' => '#^/knowledge/findings/([A-Za-z0-9._-]+)$#',
                'knowledge_proposal' => '#^/knowledge/proposals/([A-Za-z0-9._-]+)$#',
                'knowledge_guidance' => '#^/knowledge/guidance/([A-Za-z0-9._-]+)$#',
            ] as $route => $pattern) {
                $knowledgeId = $this->knowledgeId($request->path, $pattern);
                if ($knowledgeId !== null) {
                    return ['route' => $route, 'knowledge_id' => $knowledgeId];
                }
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)$#')) !== null) {
                return ['route' => 'task', 'task_id' => $taskId];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/learning$#')) !== null) {
                return ['route' => 'task_learning', 'task_id' => $taskId];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/context$#')) !== null) {
                return ['route' => 'context', 'task_id' => $taskId];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/work$#')) !== null) {
                return ['route' => 'work', 'task_id' => $taskId];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/evidence$#')) !== null) {
                return ['route' => 'evidence', 'task_id' => $taskId];
            }
            if (($taskId = $this->taskId($request->path, '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/history$#')) !== null) {
                return ['route' => 'history', 'task_id' => $taskId];
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
                'runner_run' => '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/runner/run$#',
                'runner_resume' => '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/runner/resume$#',
                'runner_cancel' => '#^/task/([A-Za-z][A-Za-z0-9]*-[0-9]+)/runner/cancel$#',
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

    private function knowledgeId(string $path, string $pattern): ?string
    {
        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
