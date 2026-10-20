<?php

declare(strict_types=1);

// Builds a small but realistic Learning project: a few Findings, a LearningNote, and a board card,
// so the Knowledge page has counts and content to render. Usage: php fixture.php <project-root>
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;
use voku\AgentLoop\ProjectLayout;

$root = $argv[1] ?? throw new RuntimeException('project root required');
mkdir($root . '/.agent-loop/todo/cards', 0o775, true);
file_put_contents($root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** APP\n");
foreach (['APP-1', 'APP-2'] as $card) {
    file_put_contents($root . '/.agent-loop/todo/cards/' . $card . '.md', "# {$card}: Card {$card}\n\n- **Lane:** BACKLOG\n- **Status:** todo\n- **Created:** 2026-10-01\n\n## Summary\nA card for the rendered audit.\n\n## Task Brief\nBrief.\n\n## Validation\ncomposer test\n");
}
$learning = (new ProjectLayout($root))->learningRoot();

$first = null;
foreach (['APP-1', 'APP-2', 'APP-3'] as $index => $task) {
    $created = (new FindingCreator())->createValidated(
        root: $learning,
        taskId: $task,
        session: 'session-' . $task,
        createdBy: 'ci',
        scope: ['src/Example' . $index . '.php'],
        observation: 'Observation ' . $index . ': a deliberately long observation sentence so wrapping behaviour is exercised in the rendered Knowledge views.',
        evidence: [['type' => 'manual_verification', 'summary' => 'Reproduced in CI.']],
        hypothesis: 'Hypothesis ' . $index,
        validatedConclusion: 'Conclusion ' . $index . ' stands.',
        confidence: 'high',
        sensitivity: 'public',
        classification: LearningClassification::ADD_LEARNING_NOTE,
        patternKey: 'ci.pattern_' . $index,
        validationCase: new ValidationCase('given ' . $index, 'when ' . $index, 'then ' . $index),
    );
    $first ??= $created->finding->id;
}

(new LearningNoteService())->publish(
    $learning,
    new LearningNoteDraft(
        sourceFindings: [$first],
        sourceProposals: [],
        tags: ['ci'],
        repositoryEvidence: [],
        content: new LearningNoteContent(
            title: 'A note with a rather long title that must wrap instead of widening the page on a narrow viewport',
            context: 'Context.',
            guidance: 'Guidance.',
            whyItWorks: 'Because.',
            whenToApply: 'Always.',
            whenNotToApply: 'Never.',
            verification: 'Look at it.',
        ),
    ),
    $root,
);
echo $root, "\n";
