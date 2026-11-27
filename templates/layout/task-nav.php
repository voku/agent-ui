<?php

use voku\AgentUi\View\TemplateRenderer;

/**
 * One navigation for every route a task has, ordered by how a task is worked.
 *
 * The task story - where it stands, what was agreed, what was done, what proves
 * it, what changed - is the primary row. Context detail, the Prompt Workbench,
 * Learning and card editing are still one click away under "More", a native
 * <details> that opens by itself when one of its views is current. Every route
 * and URL is unchanged; only the emphasis moved.
 *
 * @var string $taskNavId      the task the links address
 * @var string $taskNavCurrent the view being rendered
 */
$taskNavPrimary = [
    '' => 'Task',
    '/progress' => 'Workflow',
    '/contract' => 'Contract',
    '/work' => 'Work & review',
    '/evidence' => 'Evidence',
    '/history' => 'History',
];
$taskNavMore = [
    '/context' => 'Context & constraints',
    '/prompts' => 'Prompts',
    '/learning' => 'Learning',
    '/edit' => 'Edit card',
];
$taskNavMoreCurrent = array_key_exists($taskNavCurrent, $taskNavMore);
$taskNavLink = static function (string $suffix, string $label) use ($taskNavId, $taskNavCurrent): string {
    $current = $suffix === $taskNavCurrent;

    return '<li><a class="btn' . ($current ? ' btn--current' : '') . '" href="/task/'
        . TemplateRenderer::escape($taskNavId) . $suffix . '"'
        . ($current ? ' aria-current="page"' : '') . '>' . TemplateRenderer::escape($label) . '</a></li>';
};
?>
<nav class="task-nav" aria-label="Task views">
    <ul class="task-nav__links">
        <?php foreach ($taskNavPrimary as $suffix => $label): ?>
            <?= $taskNavLink($suffix, $label) ?>
        <?php endforeach; ?>
    </ul>
    <details class="task-nav__more"<?= $taskNavMoreCurrent ? ' open' : '' ?>>
        <summary class="btn<?= $taskNavMoreCurrent ? ' btn--current' : '' ?>">More<span class="visually-hidden"> task views</span></summary>
        <ul class="task-nav__links">
            <?php foreach ($taskNavMore as $suffix => $label): ?>
                <?= $taskNavLink($suffix, $label) ?>
            <?php endforeach; ?>
        </ul>
    </details>
</nav>
