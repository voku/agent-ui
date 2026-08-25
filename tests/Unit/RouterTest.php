<?php

declare(strict_types=1);

namespace voku\AgentUi\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Router;

final class RouterTest extends TestCase
{
    /** @return iterable<string, array{Request, array<string, string>}> */
    public static function routes(): iterable
    {
        yield 'home' => [new Request('GET', '/'), ['route' => 'home']];
        yield 'board' => [new Request('GET', '/board'), ['route' => 'board']];
        yield 'task' => [new Request('GET', '/task/abc-12'), ['route' => 'task', 'task_id' => 'ABC-12']];
        yield 'context' => [new Request('GET', '/task/ABC-12/context'), ['route' => 'context', 'task_id' => 'ABC-12']];
        yield 'evidence' => [new Request('GET', '/task/ABC-12/evidence'), ['route' => 'evidence', 'task_id' => 'ABC-12']];
        yield 'history' => [new Request('GET', '/task/ABC-12/history'), ['route' => 'history', 'task_id' => 'ABC-12']];
        yield 'handoff' => [new Request('GET', '/task/ABC-12/handoff'), ['route' => 'handoff', 'task_id' => 'ABC-12']];
        yield 'approve' => [new Request('POST', '/task/abc-12/approve'), ['route' => 'approve', 'task_id' => 'ABC-12']];
        yield 'review ack' => [new Request('POST', '/task/ABC-12/review-ack'), ['route' => 'review_ack', 'task_id' => 'ABC-12']];
        yield 'learning' => [new Request('POST', '/task/ABC-12/learning'), ['route' => 'learning', 'task_id' => 'ABC-12']];
        yield 'runner run' => [new Request('POST', '/task/ABC-12/runner/run'), ['route' => 'runner_run', 'task_id' => 'ABC-12']];
        yield 'runner resume' => [new Request('POST', '/task/ABC-12/runner/resume'), ['route' => 'runner_resume', 'task_id' => 'ABC-12']];
        yield 'runner cancel' => [new Request('POST', '/task/ABC-12/runner/cancel'), ['route' => 'runner_cancel', 'task_id' => 'ABC-12']];
    }

    /** @param array<string, string> $expected */
    #[DataProvider('routes')]
    public function testMatchesSupportedRoutes(Request $request, array $expected): void
    {
        self::assertSame($expected, (new Router())->match($request));
    }

    public function testRejectsUnsupportedMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->match(new Request('PUT', '/task/ABC-1/runner/run'));
    }

    public function testRejectsUnsafeTaskIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->match(new Request('GET', '/task/../../etc/passwd'));
    }
}
