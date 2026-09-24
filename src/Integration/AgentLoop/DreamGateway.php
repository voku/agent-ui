<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLoop;

use voku\AgentLoop\Workflow\WorkflowDreamReport;
use voku\AgentLoop\Workflow\WorkflowDreamService;

/**
 * Dream as agent-loop exposes it to hosts.
 *
 * The UI decides nothing here: agent-loop resolves the Learning root and
 * agent-learning evaluates, suppresses and writes. A preview writes nothing; a
 * write produces candidate Proposals only, which still need the existing human
 * review before any of them becomes guidance.
 */
final readonly class DreamGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function preview(): WorkflowDreamReport
    {
        return (new WorkflowDreamService($this->projectRoot))->preview();
    }

    public function writeCandidates(): WorkflowDreamReport
    {
        return (new WorkflowDreamService($this->projectRoot))->writeCandidates();
    }
}
