<?php

declare(strict_types=1);

namespace voku\AgentUi\Integration\AgentLearning;

final class MemoryReader
{
    /**
     * @return array{
     *     rules: list<array{subject: string, rule: string, canonicalHome: string}>,
     *     archivedTasks: list<array{archivedOn: string, task: string, summary: string, reason: string, candidate: string, promotedTo: string}>
     * }
     */
    public static function parse(string $projectRoot): array
    {
        $path = $projectRoot . '/MEMORY.md';
        if (!is_file($path)) {
            return ['rules' => [], 'archivedTasks' => []];
        }

        $content = (string) file_get_contents($path);
        $rules = [];
        $archivedTasks = [];

        $lines = explode("\n", $content);
        $mode = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '## Durable repository rules')) {
                $mode = 'rules';
                continue;
            }
            if (str_starts_with($trimmed, '## Archived task learning')) {
                $mode = 'archived';
                continue;
            }
            if (str_starts_with($trimmed, '## ') && $mode !== '') {
                $mode = '';
                continue;
            }

            if (!str_starts_with($trimmed, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($trimmed, '|')));
            if (
                count($cells) < 3
                || str_starts_with($cells[0], '---')
                || $cells[0] === 'Subject'
                || $cells[0] === 'Archived on'
            ) {
                continue;
            }

            if ($mode === 'rules' && count($cells) >= 3) {
                $rules[] = [
                    'subject' => $cells[0],
                    'rule' => $cells[1],
                    'canonicalHome' => $cells[2],
                ];
            } elseif ($mode === 'archived' && count($cells) >= 6) {
                $archivedTasks[] = [
                    'archivedOn' => $cells[0],
                    'task' => $cells[1],
                    'summary' => $cells[2],
                    'reason' => $cells[3],
                    'candidate' => $cells[4],
                    'promotedTo' => $cells[5],
                ];
            }
        }

        return [
            'rules' => $rules,
            'archivedTasks' => $archivedTasks,
        ];
    }
}
