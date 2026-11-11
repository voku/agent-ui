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
 * for the keyboard too. The canonical next action lives here and only here: it
 * is the one thing every view's reader may want to run, and having it in one
 * place means no page has to show it twice.
 *
 * The next action is Loop's sentence, printed as Loop wrote it. The UI does not
 * shorten it, rank it, or decide whether it applies: doing so would make this
 * header a second opinion about the lifecycle, which is exactly the authority
 * agent-ui does not hold.
 *
 * @var TaskContext $taskContext     the facts that stay true across views
 * @var string      $taskNavCurrent  the view being rendered, for the navigation below
 */
$taskNavId = $taskContext->taskId;
?>
<section class="task-context" aria-label="Task context">
    <div class="task-context__identity">
        <p class="task-context__id"><?= TemplateRenderer::escape($taskContext->taskId) ?></p>
        <p class="task-context__title"><?= TemplateRenderer::escape($taskContext->title) ?></p>
    </div>

    <dl class="task-context__facts">
        <div class="task-context__fact">
            <dt class="provenance provenance--authority">agent-kanban · lane</dt>
            <dd class="task-context__value"><?= TemplateRenderer::escape($taskContext->lane) ?></dd>
        </div>
        <div class="task-context__fact">
            <dt class="provenance provenance--authority">agent-loop · run state</dt>
            <dd class="task-context__value">
                <span data-task-workflow-state class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($taskContext->workflowState)) ?>"><?= TemplateRenderer::escape(Presentation::label($taskContext->workflowState)) ?></span>
            </dd>
        </div>
    </dl>

    <div class="task-context__next">
        <p class="provenance provenance--authority">
            agent-loop · canonical next action
            <span data-task-next-kind class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($taskContext->nextActionKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($taskContext->nextActionKind)) ?></span>
        </p>
        <p class="task-context__hint" data-task-next-hint><?= TemplateRenderer::escape(Presentation::nextActionKindHint($taskContext->nextActionKind)) ?></p>
        <div data-task-decision-slot>
            <?php if ($taskContext->awaitsHumanDecision): ?>
                <?php // The decision link follows the typed owner projection, not the next-action kind. ?>
                <p class="task-context__decision" data-task-decision-link><a class="btn btn--primary" href="/task/<?= TemplateRenderer::escape($taskContext->taskId) ?>#decision">Go to the decision agent-loop is waiting for</a></p>
            <?php endif; ?>
        </div>
        <div class="codeblock">
            <pre id="task-context-next" data-task-next-action><?= TemplateRenderer::escape($taskContext->nextAction) ?></pre>
            <button type="button" class="copy" hidden data-copy-target="task-context-next">Copy</button><button type="button" class="terminal-insert" hidden data-terminal-insert-target="task-context-next" title="Paste into the local terminal without pressing Enter">Insert in terminal</button>
        </div>
        <?php $cmdRef = Presentation::commandReference($taskContext->nextAction); ?>
        <p class="small note" data-task-command-reference<?= $cmdRef === null ? ' hidden' : '' ?> style="margin-top:6px">
            <a data-task-command-link href="/commands#<?= TemplateRenderer::escape($cmdRef ?? '') ?>">View command reference: <code data-task-command-name><?= TemplateRenderer::escape($cmdRef ?? '') ?></code> →</a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/task-nav.php'; ?>
