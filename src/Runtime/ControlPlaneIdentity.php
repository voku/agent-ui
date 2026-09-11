<?php

declare(strict_types=1);

namespace voku\AgentUi\Runtime;

use InvalidArgumentException;

final readonly class ControlPlaneIdentity
{
    public const string SERVICE = 'agent-ui';
    public const int SCHEMA = 1;

    private function __construct(
        public string $projectId,
    ) {
    }

    public static function fromProjectRoot(string $projectRoot): self
    {
        $realProjectRoot = realpath($projectRoot);
        if ($realProjectRoot === false || !is_dir($realProjectRoot)) {
            throw new InvalidArgumentException(sprintf('Project root does not exist: %s', $projectRoot));
        }

        return new self('sha256:' . hash('sha256', $realProjectRoot));
    }

    /** @return array{service: string, schema: int, status: 'ready', project_id: string} */
    public function payload(): array
    {
        return [
            'service' => self::SERVICE,
            'schema' => self::SCHEMA,
            'status' => 'ready',
            'project_id' => $this->projectId,
        ];
    }
}
