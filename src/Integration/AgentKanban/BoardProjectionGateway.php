<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

use voku\AgentKanban\Cli\BoardContextFactory;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentLoop\ProjectLayout;

final readonly class BoardProjectionGateway
{
    private string $boardRoot;

    public function __construct(string $projectRoot)
    {
        // agent-loop owns where board state lives; a repository scaffolded by
        // `agent-loop init scaffold` keeps it below the state root, not the
        // project root. Asking the layout owner keeps both answers identical.
        $this->boardRoot = (new ProjectLayout($projectRoot))->boardRoot();
    }

    public function board(): BoardSnapshot
    {
        $context = (new BoardContextFactory())->create($this->boardRoot, null, null);
        $cards = array_map($this->snapshot(...), $context->repository->loadAll()->all());

        return new BoardSnapshot($context->config->projectPrefix, $context->config->lanes, $cards);
    }

    public function card(string $taskId): CardSnapshot
    {
        $context = (new BoardContextFactory())->create($this->boardRoot, null, null);

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
