<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

use voku\AgentKanban\Cli\BoardContextFactory;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;

final readonly class BoardProjectionGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function board(): BoardSnapshot
    {
        $context = (new BoardContextFactory())->create($this->projectRoot, null, null);
        $cards = array_map($this->snapshot(...), $context->repository->loadAll()->all());

        return new BoardSnapshot($context->config->projectPrefix, $context->config->lanes, $cards);
    }

    public function card(string $taskId): CardSnapshot
    {
        $context = (new BoardContextFactory())->create($this->projectRoot, null, null);

        return $this->snapshot($context->repository->load(CardId::fromString($taskId)));
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
