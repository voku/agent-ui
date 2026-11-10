<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentRecallCompiler;

use RuntimeException;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\WorkflowTaskId;
use voku\AgentRecallCompiler\Output\CompiledContextExplanationReader;

final readonly class ContextExplanationGateway
{
    public function __construct(private string $projectRoot)
    {
    }

    public function task(string $taskId): ContextExplanationSnapshot
    {
        $validated = new WorkflowTaskId($taskId);

        try {
            $explanation = (new CompiledContextExplanationReader())->readForTask(
                RecallOutputRoot::resolve($this->projectRoot),
                $validated->value,
            );
        } catch (RuntimeException $exception) {
            error_log('agent-ui persisted Recall explanation failed validation: ' . $exception->getMessage());

            return ContextExplanationSnapshot::invalid();
        }

        return $explanation === null
            ? ContextExplanationSnapshot::missing()
            : ContextExplanationSnapshot::available($explanation);
    }

    /**
     * Map of noteId => list<string> for tasks whose compiled Recall context received that note.
     *
     * @return array<string, list<string>>
     */
    public function deliveredPrecedentsByNote(): array
    {
        $root = RecallOutputRoot::resolve($this->projectRoot);
        if (!is_dir($root)) {
            return [];
        }

        $deliveries = [];
        $entries = scandir($root);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'current') {
                continue;
            }
            if (preg_match('/^[A-Za-z][A-Za-z0-9]*-[0-9]+$/', $entry) !== 1) {
                continue;
            }
            $taskId = strtoupper($entry);
            $reportPath = $root . '/' . $entry . '/selection-report.json';
            if (!is_file($reportPath)) {
                continue;
            }

            $json = @file_get_contents($reportPath);
            if (!is_string($json)) {
                continue;
            }

            try {
                $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($data) || !isset($data['context_explain']) || !is_array($data['context_explain'])) {
                continue;
            }

            foreach ($data['context_explain'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['kind'] ?? null) !== 'learning_precedent' || ($item['selected'] ?? false) !== true) {
                    continue;
                }
                $subjectId = $item['subject_id'] ?? null;
                if (is_string($subjectId) && $subjectId !== '') {
                    $deliveries[$subjectId][] = $taskId;
                }
            }
        }

        foreach ($deliveries as $noteId => $tasks) {
            $unique = array_values(array_unique($tasks));
            sort($unique, SORT_STRING);
            $deliveries[$noteId] = $unique;
        }

        return $deliveries;
    }
}
