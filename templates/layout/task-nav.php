<?php

use voku\AgentUi\View\TemplateRenderer;

/**
 * One navigation for every route a task has, grouped by what the developer wants.
 *
 * Each task view used to repeat its own partial button row, which is how
 * `/task/{id}/learning` ended up reachable only from the Knowledge detail
 * pages and how the task Prompt Workbench ended up with no way back. Collecting
 * them here fixed reachability but left ten equal buttons, which is a list, not a
 * hierarchy: "Edit card" sat beside "Workflow" as though a developer weighs them
 * the same way.
 *
 * The groups follow the task story - where it stands, what was agreed, what was
 * done, what proves it, and the tools around it. Every route and URL is
 * unchanged; only their arrangement carries meaning now.
 *
 * @var string $taskNavId      the task the links address
 * @var string $taskNavCurrent the view being rendered
 */
$taskNavGroups = [
    'Summary' => [
        '' => 'Task',
        '/progress' => 'Workflow',
    ],
    'Intent' => [
        '/contract' => 'Contract',
        '/context' => 'Context & constraints',
    ],
    'Execution' => [
        '/work' => 'Work & review',
    ],
    'Evidence' => [
        '/evidence' => 'Evidence & audit',
        '/history' => 'History',
    ],
    'Tools' => [
        '/prompts' => 'Prompts',
        '/learning' => 'Learning',
        '/edit' => 'Edit card',
    ],
];
?>
<nav class="task-nav" aria-label="Task views">
    <?php foreach ($taskNavGroups as $group => $views): ?>
        <div class="task-nav__group">
            <span class="task-nav__label" aria-hidden="true"><?= TemplateRenderer::escape($group) ?></span>
            <ul class="task-nav__links">
                <?php foreach ($views as $suffix => $label): ?>
                    <?php $current = $suffix === $taskNavCurrent; ?>
                    <li><a class="btn<?= $current ? ' btn--current' : '' ?>"
                           href="/task/<?= TemplateRenderer::escape($taskNavId) ?><?= $suffix ?>"
                        <?= $current ? ' aria-current="page"' : '' ?>><span class="visually-hidden"><?= TemplateRenderer::escape($group) ?>: </span><?= TemplateRenderer::escape($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</nav>
