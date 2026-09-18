<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Cli\CommandCatalog;
use voku\AgentLoop\Cli\CommandDescriptor;
use voku\AgentLoop\Cli\CommandGroup;
use voku\AgentLoop\Cli\CommandId;

/**
 * Gateway providing typed access to the Command Catalog owned by agent-loop.
 */
final readonly class CommandCatalogGateway
{
    /**
     * @return array<string, CommandDescriptor>
     */
    public function all(): array
    {
        return CommandCatalog::all();
    }

    /**
     * @return list<CommandDescriptor>
     */
    public function list(): array
    {
        return array_values(CommandCatalog::all());
    }

    /**
     * @return list<CommandDescriptor>
     */
    public function byGroup(CommandGroup $group): array
    {
        return CommandCatalog::byGroup($group);
    }

    public function find(CommandId|string $id): ?CommandDescriptor
    {
        if (is_string($id)) {
            $enum = CommandId::tryFrom($id);
            if ($enum === null) {
                return null;
            }
            $id = $enum;
        }

        return CommandCatalog::find($id);
    }

    /**
     * Resolves an action string (e.g. from workflow next_action) to a matching CommandId.
     */
    public function resolveCommandId(string $action): ?CommandId
    {
        if (preg_match('#^(?:\./)?(?:vendor/bin/|bin/)?agent-loop\s+([a-zA-Z0-9:_-]+)#', trim($action), $matches) !== 1) {
            return null;
        }

        return CommandId::tryFrom($matches[1]);
    }
}
