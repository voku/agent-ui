<?php

declare(strict_types=1);

namespace voku\AgentUi\Feature\Commands;

use voku\AgentLoop\Cli\CommandDescriptor;
use voku\AgentLoop\Cli\CommandGroup;
use voku\AgentLoop\Cli\CommandOwner;
use voku\AgentUi\Http\Request;
use voku\AgentUi\Http\Response;
use voku\AgentUi\Integration\AgentLoop\CommandCatalogGateway;
use voku\AgentUi\View\TemplateRenderer;

final readonly class CommandsAction
{
    public function __construct(
        private CommandCatalogGateway $gateway,
        private TemplateRenderer $templates,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $selectedGroup = $request->query['group'] ?? 'all';
        $selectedOwner = $request->query['owner'] ?? 'all';
        $query = trim($request->query['q'] ?? '');

        $allDescriptors = $this->gateway->list();

        /** @var list<CommandDescriptor> $filtered */
        $filtered = [];
        foreach ($allDescriptors as $descriptor) {
            if ($selectedGroup !== 'all' && $descriptor->group->value !== $selectedGroup) {
                continue;
            }
            if ($selectedOwner !== 'all' && $descriptor->owner->value !== $selectedOwner) {
                continue;
            }
            if ($query !== '') {
                $needle = strtolower($query);
                $haystack = strtolower(
                    $descriptor->id->value . ' ' .
                    $descriptor->summary . ' ' .
                    ($descriptor->usage ?? '') . ' ' .
                    $descriptor->owner->value . ' ' .
                    $descriptor->group->title(),
                );
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }
            $filtered[] = $descriptor;
        }

        /** @var array<string, list<CommandDescriptor>> $grouped */
        $grouped = [];
        foreach (CommandGroup::cases() as $group) {
            $groupCommands = array_values(array_filter(
                $filtered,
                static fn(CommandDescriptor $d): bool => $d->group === $group,
            ));
            if ($groupCommands !== []) {
                $grouped[$group->value] = $groupCommands;
            }
        }

        return Response::html($this->templates->render('commands/index', [
            'commands' => $filtered,
            'grouped' => $grouped,
            'groups' => CommandGroup::cases(),
            'owners' => CommandOwner::cases(),
            'selectedGroup' => $selectedGroup,
            'selectedOwner' => $selectedOwner,
            'query' => $query,
            'totalCount' => count($allDescriptors),
        ]));
    }
}
