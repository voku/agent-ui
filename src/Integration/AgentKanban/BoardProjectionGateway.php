<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

use voku\AgentKanban\Cli\BoardContext;
use voku\AgentKanban\Cli\BoardContextFactory;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Transition\TransitionPolicy;
use voku\AgentLoop\ProjectLayout;

final readonly class BoardProjectionGateway
{
    private ProjectLayout $layout;

    public function __construct(string $projectRoot)
    {
        $this->layout = new ProjectLayout($projectRoot);
    }

    public function board(?string $boardId = null): BoardSnapshot
    {
        $factory = new BoardContextFactory();
        $allContexts = $factory->createAll($this->layout->boardRoot());

        $activeContext = $this->context($boardId);
        $activeKey = $activeContext->config->id ?? $activeContext->config->projectPrefix;
        $policy = new TransitionPolicy($activeContext->config);
        $cards = [];
        foreach ($activeContext->repository->loadAllLenient()->cards->all() as $c) {
            $cards[] = $this->snapshot($c, $activeKey, $policy->allowedTargets($c->lane));
        }

        $boards = [];
        foreach ($allContexts as $key => $ctx) {
            $boardKey = $ctx->config->id ?? ($key !== '' ? $key : $ctx->config->projectPrefix);
            $boardTitle = $ctx->config->title ?? $ctx->config->projectPrefix;
            $count = $ctx->repository->loadAllLenient()->cards->count();
            $boards[] = new BoardSummary(
                id: $boardKey,
                title: $boardTitle,
                projectPrefix: $ctx->config->projectPrefix,
                cardCount: $count,
                active: $boardKey === $activeKey || $ctx->config->projectPrefix === $activeContext->config->projectPrefix,
            );
        }

        return new BoardSnapshot(
            projectPrefix: $activeContext->config->projectPrefix,
            lanes: $activeContext->config->lanes,
            cards: $cards,
            id: $activeContext->config->id ?? $activeKey,
            title: $activeContext->config->title ?? $activeContext->config->projectPrefix,
            boards: $boards,
        );
    }

    public function card(string $taskId): CardSnapshot
    {
        $factory = new BoardContextFactory();
        $allContexts = $factory->createAll($this->layout->boardRoot());
        $cardId = CardId::fromString($taskId);

        foreach ($allContexts as $context) {
            try {
                $loaded = $context->repository->load($cardId);
                $transitions = (new TransitionPolicy($context->config))->allowedTargets($loaded->lane);
                $boardKey = $context->config->id ?? $context->config->projectPrefix;

                return $this->snapshot($loaded, $boardKey, $transitions);
            } catch (\Throwable) {
                continue;
            }
        }

        $context = $this->context();
        $loaded = $context->repository->load($cardId);
        $transitions = (new TransitionPolicy($context->config))->allowedTargets($loaded->lane);
        $boardKey = $context->config->id ?? $context->config->projectPrefix;

        return $this->snapshot($loaded, $boardKey, $transitions);
    }

    private function context(?string $boardId = null): BoardContext
    {
        return (new BoardContextFactory())->create($this->layout->boardRoot(), null, null, $boardId);
    }

    /**
     * @param list<string> $allowedTransitions
     */
    private function snapshot(Card $card, ?string $boardId = null, array $allowedTransitions = []): CardSnapshot
    {
        return new CardSnapshot(
            id: $card->id->toString(),
            title: $card->title,
            lane: $card->lane->toString(),
            status: $card->status->toString(),
            summary: $card->summary,
            nextAction: $card->nextAction,
            validation: $card->validation,
            priority: $card->priority,
            assignee: $card->assignee,
            taskBrief: $card->taskBrief,
            revision: $card->revision->toString(),
            claimActor: $card->claim?->actor,
            allowedTransitions: $allowedTransitions,
            boardId: $boardId,
        );
    }
}
