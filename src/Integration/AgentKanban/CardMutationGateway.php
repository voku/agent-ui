<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentKanban;

use voku\AgentKanban\Cli\BoardContext;
use voku\AgentKanban\Cli\BoardContextFactory;
use voku\AgentKanban\Domain\Card;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\CardStatus;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Transition\TransitionPolicy;
use voku\AgentLoop\ProjectLayout;

final readonly class CardMutationGateway
{
    private ProjectLayout $layout;

    public function __construct(string $projectRoot)
    {
        $this->layout = new ProjectLayout($projectRoot);
    }

    /**
     * @return array<string, BoardContext>
     */
    public function allContexts(): array
    {
        return (new BoardContextFactory())->createAll($this->layout->boardRoot());
    }

    public function context(?string $boardId = null): BoardContext
    {
        return (new BoardContextFactory())->create($this->layout->boardRoot(), null, null, $boardId);
    }

    public function contextForCard(string $taskId): BoardContext
    {
        $cardId = CardId::fromString($taskId);
        $all = $this->allContexts();
        foreach ($all as $ctx) {
            try {
                $ctx->repository->load($cardId);

                return $ctx;
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->context();
    }

    public function suggestNextId(?string $boardId = null): string
    {
        $context = $this->context($boardId);
        $prefix = $context->config->projectPrefix;
        $max = 0;
        foreach ($context->repository->loadAllLenient()->cards->all() as $card) {
            if ($card->id->prefix === $prefix && $card->id->number > $max) {
                $max = $card->id->number;
            }
        }

        return sprintf('%s-%d', $prefix, $max + 1);
    }

    /**
     * @return list<string>
     */
    public function availableLanes(?string $boardId = null): array
    {
        return $this->context($boardId)->config->lanes;
    }

    /**
     * @return list<string>
     */
    public function allowedTransitions(string $taskId): array
    {
        $context = $this->contextForCard($taskId);
        $card = $context->repository->load(CardId::fromString($taskId));
        $policy = new TransitionPolicy($context->config);

        return $policy->allowedTargets($card->lane);
    }

    public function create(
        string $cardId,
        string $title,
        string $lane,
        string $status = '',
        string $summary = '',
        string $taskBrief = '',
        string $nextAction = '',
        string $validation = '',
        ?int $priority = null,
        ?string $assignee = null,
        ?string $boardId = null,
    ): CardSnapshot {
        $context = $this->context($boardId);
        $service = new CardMutationService($context->rootPath, $context->config, $context->repository);
        $cId = CardId::fromString($cardId);
        $result = $service->create(
            id: $cId,
            lane: Lane::fromString($lane),
            status: CardStatus::fromString($status),
            title: $title,
            summary: $summary,
            taskBrief: $taskBrief,
            nextAction: $nextAction,
            validation: $validation,
        );

        $card = $result->card;
        if ($priority !== null || ($assignee !== null && trim($assignee) !== '')) {
            $updateResult = $service->update(
                id: $cId,
                assignee: $assignee !== null && trim($assignee) !== '' ? trim($assignee) : null,
                priority: $priority,
            );
            $card = $updateResult->card;
        }

        $boardKey = $context->config->id ?? $context->config->projectPrefix;
        $policy = new TransitionPolicy($context->config);

        return $this->snapshot($card, $boardKey, $policy->allowedTargets($card->lane));
    }

    public function update(
        string $taskId,
        ?string $title = null,
        ?string $status = null,
        ?string $summary = null,
        ?string $taskBrief = null,
        ?string $nextAction = null,
        ?string $validation = null,
        ?int $priority = null,
        ?string $assignee = null,
        ?string $expectedRevision = null,
    ): CardSnapshot {
        $context = $this->contextForCard($taskId);
        $service = new CardMutationService($context->rootPath, $context->config, $context->repository);
        $cId = CardId::fromString($taskId);

        $revision = $expectedRevision !== null && trim($expectedRevision) !== ''
            ? CardRevision::fromHex($expectedRevision)
            : null;

        $result = $service->update(
            $cId,
            title: $title,
            status: $status !== null ? CardStatus::fromString($status) : null,
            assignee: $assignee,
            summary: $summary,
            nextAction: $nextAction,
            validation: $validation,
            priority: $priority,
            taskBrief: $taskBrief,
            expectedRevision: $revision,
        );

        $card = $result->card;
        $boardKey = $context->config->id ?? $context->config->projectPrefix;
        $policy = new TransitionPolicy($context->config);

        return $this->snapshot($card, $boardKey, $policy->allowedTargets($card->lane));
    }

    public function move(
        string $taskId,
        string $targetLane,
        ?string $actor = null,
        ?string $expectedRevision = null,
    ): CardSnapshot {
        $context = $this->contextForCard($taskId);
        $service = new CardMutationService($context->rootPath, $context->config, $context->repository);
        $cId = CardId::fromString($taskId);

        $revision = $expectedRevision !== null && trim($expectedRevision) !== ''
            ? CardRevision::fromHex($expectedRevision)
            : null;

        $result = $service->move(
            $cId,
            Lane::fromString($targetLane),
            actor: $actor,
            expectedRevision: $revision,
        );

        $card = $result->card;
        $boardKey = $context->config->id ?? $context->config->projectPrefix;
        $policy = new TransitionPolicy($context->config);

        return $this->snapshot($card, $boardKey, $policy->allowedTargets($card->lane));
    }

    public function claim(
        string $taskId,
        string $actor,
        bool $moveToDoing = false,
        ?string $expectedRevision = null,
    ): CardSnapshot {
        $context = $this->contextForCard($taskId);
        $service = new CardMutationService($context->rootPath, $context->config, $context->repository);
        $cId = CardId::fromString($taskId);

        $revision = $expectedRevision !== null && trim($expectedRevision) !== ''
            ? CardRevision::fromHex($expectedRevision)
            : null;

        $result = $service->claim(
            $cId,
            actor: $actor,
            moveToDoing: $moveToDoing,
            expectedRevision: $revision,
        );

        $card = $result->card;
        $boardKey = $context->config->id ?? $context->config->projectPrefix;
        $policy = new TransitionPolicy($context->config);

        return $this->snapshot($card, $boardKey, $policy->allowedTargets($card->lane));
    }

    public function release(
        string $taskId,
        string $actor,
        ?string $expectedRevision = null,
    ): CardSnapshot {
        $context = $this->contextForCard($taskId);
        $service = new CardMutationService($context->rootPath, $context->config, $context->repository);
        $cId = CardId::fromString($taskId);

        $revision = $expectedRevision !== null && trim($expectedRevision) !== ''
            ? CardRevision::fromHex($expectedRevision)
            : null;

        $result = $service->release(
            $cId,
            actor: $actor,
            expectedRevision: $revision,
        );

        $card = $result->card;
        $boardKey = $context->config->id ?? $context->config->projectPrefix;
        $policy = new TransitionPolicy($context->config);

        return $this->snapshot($card, $boardKey, $policy->allowedTargets($card->lane));
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
