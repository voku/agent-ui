<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

use voku\AgentKanban\Cli\BoardContext;
use voku\AgentKanban\Cli\BoardContextFactory;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
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
        $cards = array_map($this->snapshot(...), $activeContext->repository->loadAllLenient()->cards->all());

        $boards = [];
        $activeKey = $activeContext->config->id ?? $activeContext->config->projectPrefix;
        foreach ($allContexts as $key => $ctx) {
            $boardKey = $ctx->config->id ?? (is_string($key) && $key !== '' ? $key : $ctx->config->projectPrefix);
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
                return $this->snapshot($context->repository->load($cardId));
            } catch (\Throwable) {
                continue;
            }
        }

        $context = $this->context();

        return $this->snapshot($context->repository->load($cardId));
    }

    private function context(?string $boardId = null): BoardContext
    {
        return (new BoardContextFactory())->create($this->layout->boardRoot(), null, null, $boardId);
    }

    private function snapshot(Card $card): CardSnapshot
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
        );
    }
}
