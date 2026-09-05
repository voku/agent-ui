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
        yield 'setup' => [new Request('GET', '/setup'), ['route' => 'setup']];
        yield 'prompts' => [new Request('GET', '/prompts'), ['route' => 'prompts']];
        yield 'prompts post' => [new Request('POST', '/prompts'), ['route' => 'prompts']];
        yield 'board' => [new Request('GET', '/board'), ['route' => 'board']];
        yield 'board new' => [new Request('GET', '/board/new'), ['route' => 'board_new']];
        yield 'task new' => [new Request('GET', '/task/new'), ['route' => 'board_new']];
        yield 'board create' => [new Request('POST', '/board/new'), ['route' => 'board_create']];
        yield 'task new post' => [new Request('POST', '/task/new'), ['route' => 'board_create']];
        yield 'map' => [new Request('GET', '/map'), ['route' => 'map']];
        yield 'map graph' => [new Request('GET', '/map/graph'), ['route' => 'map_graph']];
        yield 'map symbol' => [new Request('GET', '/map/symbol'), ['route' => 'map_symbol']];
        yield 'map context' => [new Request('GET', '/map/context'), ['route' => 'map_context']];
        yield 'knowledge' => [new Request('GET', '/knowledge'), ['route' => 'knowledge']];
        yield 'knowledge finding' => [new Request('GET', '/knowledge/findings/finding.2026-08-26.ab12cd'), ['route' => 'knowledge_finding', 'knowledge_id' => 'finding.2026-08-26.ab12cd']];
        yield 'knowledge proposal' => [new Request('GET', '/knowledge/proposals/proposal.2026-08-26.123'), ['route' => 'knowledge_proposal', 'knowledge_id' => 'proposal.2026-08-26.123']];
        yield 'knowledge guidance' => [new Request('GET', '/knowledge/guidance/proposal.2026-08-26.123'), ['route' => 'knowledge_guidance', 'knowledge_id' => 'proposal.2026-08-26.123']];
        yield 'task' => [new Request('GET', '/task/abc-12'), ['route' => 'task', 'task_id' => 'ABC-12']];
        yield 'task edit' => [new Request('GET', '/task/abc-12/edit'), ['route' => 'task_edit', 'task_id' => 'ABC-12']];
        yield 'task update' => [new Request('POST', '/task/abc-12/edit'), ['route' => 'task_update', 'task_id' => 'ABC-12']];
        yield 'task move' => [new Request('POST', '/task/abc-12/move'), ['route' => 'task_move', 'task_id' => 'ABC-12']];
        yield 'task claim' => [new Request('POST', '/task/abc-12/claim'), ['route' => 'task_claim', 'task_id' => 'ABC-12']];
        yield 'task release' => [new Request('POST', '/task/abc-12/release'), ['route' => 'task_release', 'task_id' => 'ABC-12']];
        yield 'task contract' => [new Request('GET', '/task/abc-12/contract'), ['route' => 'task_contract', 'task_id' => 'ABC-12']];
        yield 'task contract propose' => [new Request('POST', '/task/abc-12/contract'), ['route' => 'task_contract_propose', 'task_id' => 'ABC-12']];
        yield 'task prompts' => [new Request('GET', '/task/abc-12/prompts'), ['route' => 'task_prompts', 'task_id' => 'ABC-12']];
        yield 'task prompts post' => [new Request('POST', '/task/abc-12/prompts'), ['route' => 'task_prompts', 'task_id' => 'ABC-12']];
        yield 'task learning' => [new Request('GET', '/task/abc-12/learning'), ['route' => 'task_learning', 'task_id' => 'ABC-12']];
        yield 'context' => [new Request('GET', '/task/ABC-12/context'), ['route' => 'context', 'task_id' => 'ABC-12']];
        yield 'work' => [new Request('GET', '/task/ABC-12/work'), ['route' => 'work', 'task_id' => 'ABC-12']];
        yield 'evidence' => [new Request('GET', '/task/ABC-12/evidence'), ['route' => 'evidence', 'task_id' => 'ABC-12']];
        yield 'history' => [new Request('GET', '/task/ABC-12/history'), ['route' => 'history', 'task_id' => 'ABC-12']];
        yield 'handoff' => [new Request('GET', '/task/ABC-12/handoff'), ['route' => 'handoff', 'task_id' => 'ABC-12']];
        yield 'approve' => [new Request('POST', '/task/abc-12/approve'), ['route' => 'approve', 'task_id' => 'ABC-12']];
        yield 'review ack' => [new Request('POST', '/task/ABC-12/review-ack'), ['route' => 'review_ack', 'task_id' => 'ABC-12']];
        yield 'learning decision' => [new Request('POST', '/task/ABC-12/learning'), ['route' => 'learning', 'task_id' => 'ABC-12']];
        yield 'runner run' => [new Request('POST', '/task/ABC-12/runner/run'), ['route' => 'runner_run', 'task_id' => 'ABC-12']];
        yield 'runner resume' => [new Request('POST', '/task/ABC-12/runner/resume'), ['route' => 'runner_resume', 'task_id' => 'ABC-12']];
        yield 'runner cancel' => [new Request('POST', '/task/ABC-12/runner/cancel'), ['route' => 'runner_cancel', 'task_id' => 'ABC-12']];
        yield 'setup install' => [new Request('POST', '/setup/codex/install'), ['route' => 'setup_install', 'agent' => 'codex']];
        yield 'setup remove' => [new Request('POST', '/setup/claude/remove'), ['route' => 'setup_remove', 'agent' => 'claude']];
        yield 'setup policy' => [new Request('POST', '/setup/opencode/sync-policy'), ['route' => 'setup_sync_policy', 'agent' => 'opencode']];
        yield 'setup git' => [new Request('POST', '/setup/sync-git'), ['route' => 'setup_sync_git']];
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

    public function testRejectsUnsafeKnowledgeIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->match(new Request('GET', '/knowledge/findings/../../etc/passwd'));
    }

    public function testRejectsUnsafeSetupHostIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Router())->match(new Request('POST', '/setup/../../install'));
    }
}
