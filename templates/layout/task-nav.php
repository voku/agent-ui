<?php
use voku\AgentUi\View\TemplateRenderer;

/**
 * One navigation for every route a task has.
 *
 * Each task view used to repeat its own partial button row, which is how
 * `/task/{id}/learning` ended up reachable only from the Knowledge detail
 * pages and how the task Prompt Workbench ended up with no way back.
 *
 * @var string $taskNavId      the task the links address
 * @var string $taskNavCurrent the view being rendered
 */
$taskNavViews = [
    '' => 'Task',
    '/contract' => 'Contract',
    '/edit' => 'Edit card',
    '/context' => 'Context & constraints',
    '/work' => 'Work & review',
    '/evidence' => 'Evidence & audit',
    '/history' => 'History',
    '/prompts' => 'Prompts',
    '/learning' => 'Learning',
];
?>
<nav class="task-nav" aria-label="Task views">
    <?php foreach ($taskNavViews as $suffix => $label): ?>
        <?php $current = $suffix === $taskNavCurrent; ?>
        <a class="btn<?= $current ? ' btn--current' : '' ?>"
           href="/task/<?= TemplateRenderer::escape($taskNavId) ?><?= $suffix ?>"
            <?= $current ? ' aria-current="page"' : '' ?>><?= TemplateRenderer::escape($label) ?></a>
    <?php endforeach; ?>
</nav>
