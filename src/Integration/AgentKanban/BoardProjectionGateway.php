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

    public function board(): BoardSnapshot
    {
        $context = $this->context();
        $cards = array_map($this->snapshot(...), $context->repository->loadAll()->all());

        return new BoardSnapshot($context->config->projectPrefix, $context->config->lanes, $cards);
    }

    public function card(string $taskId): CardSnapshot
    {
        $context = $this->context();

        return $this->snapshot($context->repository->load(CardId::fromString($taskId)));
    }

    private function context(): BoardContext
    {
        // ProjectLayout owns the active board location. Resolve it for each read
        // so a configured board-root relocation does not leave this long-lived
        // UI gateway pinned to the path that existed at construction time.
        return (new BoardContextFactory())->create($this->layout->boardRoot(), null, null);
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
