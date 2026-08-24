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
        yield 'evidence' => [new Request('GET', '/task/ABC-12/evidence'), ['route' => 'evidence', 'task_id' => 'ABC-12']];
    }

    /** @param array<string, string> $expected */
    #[DataProvider('routes')]
    public function testMatchesSupportedReadRoutes(Request $request, array $expected): void
    {
        self::assertSame($expected, (new Router())->match($request));
    }

    public function testRejectsMutationInReadOnlyVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->match(new Request('POST', '/task/ABC-1'));
    }

    public function testRejectsUnsafeTaskIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->match(new Request('GET', '/task/../../etc/passwd'));
    }
}
