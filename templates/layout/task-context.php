<?php

use voku\AgentUi\Feature\Task\TaskContext;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/**
 * The task header that does not change when the view does.
 *
 * Ten task views each answered their own question and assumed the reader still
 * remembered the rest. Opening `/task/{id}/evidence` after two days told you what
 * the evidence was, but not which task, not where the workflow stood, and not
 * what it expected next - so the reader went back to `/task/{id}` to look up the
 * things that had not changed.
 *
 * Those facts live here instead, ahead of the page body and ahead of the task
 * navigation, so they arrive before the view-specific answer and in that order
 * for the keyboard too.
 *
 * The next action is Loop's sentence, printed as Loop wrote it. The UI does not
 * shorten it, rank it against anything, or decide whether it applies: doing so
 * would make this header a second opinion about the lifecycle, which is exactly
 * the authority agent-ui does not hold.
 *
 * @var TaskContext $taskContext     the facts that stay true across views
 * @var string      $taskNavCurrent  the view being rendered, for the navigation below
 */
$taskNavId = $taskContext->taskId;
?>
<section class="task-context" aria-label="Task context">
    <div class="task-context__identity">
        <span class="task-context__id"><?= TemplateRenderer::escape($taskContext->taskId) ?></span>
        <p class="task-context__title"><?= TemplateRenderer::escape($taskContext->title) ?></p>
    </div>

    <div class="task-context__facts">
        <div class="task-context__fact">
            <p class="provenance provenance--authority">agent-kanban · lane</p>
            <p class="task-context__value"><?= TemplateRenderer::escape($taskContext->lane) ?></p>
        </div>
        <div class="task-context__fact">
            <p class="provenance provenance--authority">agent-loop · run state</p>
            <p class="task-context__value">
                <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($taskContext->workflowState)) ?>"><?= TemplateRenderer::escape(Presentation::label($taskContext->workflowState)) ?></span>
            </p>
        </div>
    </div>

    <div class="task-context__next">
        <p class="provenance provenance--authority">agent-loop · canonical next action</p>
        <p class="task-context__action">
            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($taskContext->nextActionKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($taskContext->nextActionKind)) ?></span>
            <code><?= TemplateRenderer::escape($taskContext->nextAction) ?></code>
        </p>
    </div>
</section>

<?php require __DIR__ . '/task-nav.php'; ?>
