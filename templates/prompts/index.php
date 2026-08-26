<?php
use voku\AgentRecallCompiler\OperatingPromptArgument;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Feature\PromptWorkbench\PromptWorkbenchViewModel;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{workbench: PromptWorkbenchViewModel} $model */
$workbench = $model['workbench'];
$selectedRecipe = null;
foreach ($workbench->recipes as $recipe) {
    if ($recipe->id === $workbench->selectedRecipeId) {
        $selectedRecipe = $recipe;
        break;
    }
}
$formAction = $workbench->taskAware && $workbench->taskId !== null
    ? '/task/' . rawurlencode($workbench->taskId) . '/prompts'
    : '/prompts';
$title = $workbench->taskAware && $workbench->taskId !== null
    ? $workbench->taskId . ' · Prompt Workbench · agent-ui'
    : 'Prompt Workbench · agent-ui';
$nav = 'prompts';
$projectLabel = 'deterministic prompt workbench';
require __DIR__ . '/../layout/header.php';
?>
<?php if ($workbench->taskAware && $workbench->taskId !== null): ?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($workbench->taskId) ?>"><?= TemplateRenderer::escape($workbench->taskId) ?></a><span>/</span>Prompts</p>
<?php endif; ?>
<div class="page-head">
    <?php if ($workbench->taskId !== null): ?><span class="page-head__id"><?= TemplateRenderer::escape($workbench->taskId) ?></span><?php endif; ?>
    <h1>Prompt Workbench</h1>
    <p class="lede">Choose what you are trying to do, then compose a workflow-owned envelope with the explicitly selected Recall recipe. The UI owns presentation and deterministic composition, not workflow authority or recipe semantics.</p>
</div>

<?php if ($workbench->errors !== []): ?>
<section class="panel panel--attention" aria-labelledby="prompt-errors">
    <h2 id="prompt-errors">Prompt not generated</h2>
    <ul>
        <?php foreach ($workbench->errors as $error): ?><li><?= TemplateRenderer::escape($error) ?></li><?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($workbench->context !== null): ?>
<p class="eyebrow">Current Recall context</p>
<section class="panel">
    <dl class="facts">
        <div><dt>Status</dt><dd><?= TemplateRenderer::escape($workbench->context->status) ?></dd></div>
        <?php if ($workbench->context->explanation !== null): ?>
            <div><dt>Compilation</dt><dd><?= TemplateRenderer::escape($workbench->context->explanation->compilationId ?? 'unknown') ?></dd></div>
            <div><dt>Bundle</dt><dd class="mono"><?= TemplateRenderer::escape($workbench->context->explanation->bundleSha256) ?></dd></div>
            <div><dt>Selected guidance</dt><dd><?= $workbench->context->selectedGuidanceCount() ?></dd></div>
            <div><dt>Excluded guidance</dt><dd><?= $workbench->context->excludedGuidanceCount() ?></dd></div>
        <?php endif; ?>
    </dl>
    <?php if ($workbench->context->problem !== null): ?><p class="note"><?= TemplateRenderer::escape($workbench->context->problem) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<p class="eyebrow">Inputs</p>
<section class="panel">
    <form method="post" action="<?= TemplateRenderer::escape($formAction) ?>">
        <?php if (!$workbench->taskAware): ?>
            <label for="task-id"><strong>Task ID</strong></label>
            <input id="task-id" name="task_id" type="text" required value="<?= TemplateRenderer::escape($workbench->taskId ?? '') ?>" placeholder="ITPNG-346">

            <label for="goal"><strong>Goal</strong></label>
            <textarea id="goal" name="goal" rows="5" required><?= TemplateRenderer::escape($workbench->goal) ?></textarea>
        <?php else: ?>
            <p><strong>Task:</strong> <?= TemplateRenderer::escape($workbench->taskId ?? '') ?></p>
            <?php if ($workbench->taskTitle !== null): ?><p class="muted"><?= TemplateRenderer::escape($workbench->taskTitle) ?></p><?php endif; ?>
        <?php endif; ?>

        <h2>What do you need now?</h2>
        <p class="small muted">Every choice below comes from agent-recall-compiler. agent-ui groups the typed owner metadata for presentation; it does not rank, auto-select, or infer a recipe from your text.</p>

        <?php foreach ($workbench->recipeGroups() as $group): ?>
            <p class="eyebrow"><?= TemplateRenderer::escape($group->title) ?></p>
            <div class="grid">
                <?php foreach ($group->recipes as $recipe): ?>
                    <?php
                    $requiredArguments = array_values(array_filter(
                        $recipe->arguments,
                        static fn (OperatingPromptArgument $argument): bool => $argument->required,
                    ));
                    ?>
                    <label class="panel<?= $recipe->id === $workbench->selectedRecipeId ? ' panel--accent' : '' ?>">
                        <input type="radio" name="recipe" required value="<?= TemplateRenderer::escape($recipe->id) ?>"<?= $recipe->id === $workbench->selectedRecipeId ? ' checked' : '' ?>>
                        <strong><?= TemplateRenderer::escape($recipe->title) ?></strong>
                        <p class="small muted"><?= TemplateRenderer::escape($recipe->description) ?></p>
                        <p>
                            <span class="pill">L<?= $recipe->level ?></span>
                            <?php if ($requiredArguments !== []): ?><span class="pill"><?= count($requiredArguments) ?> required field<?= count($requiredArguments) === 1 ? '' : 's' ?></span><?php endif; ?>
                            <?php if ($recipe->requiresTaskContext()): ?><span class="pill">task context required</span><?php endif; ?>
                            <?php if ($recipe->requiresMutationAuthority()): ?><span class="pill">mutation authority required</span><?php endif; ?>
                        </p>
                        <?php if ($requiredArguments !== []): ?>
                            <p class="small muted">Required: <?php foreach ($requiredArguments as $index => $argument): ?><?= $index > 0 ? ', ' : '' ?><code><?= TemplateRenderer::escape($argument->name) ?></code><?php endforeach; ?></p>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit" name="action" value="select">Load selected recipe</button>

        <?php if ($selectedRecipe instanceof OperatingPromptRecipe): ?>
            <hr>
            <h2><?= TemplateRenderer::escape($selectedRecipe->title) ?></h2>
            <p><?= TemplateRenderer::escape($selectedRecipe->description) ?></p>
            <dl class="facts">
                <div><dt>Recipe</dt><dd><?= TemplateRenderer::escape($selectedRecipe->id) ?></dd></div>
                <div><dt>Purpose</dt><dd><?= TemplateRenderer::escape($selectedRecipe->purpose) ?></dd></div>
                <div><dt>Level</dt><dd>L<?= $selectedRecipe->level ?></dd></div>
                <div><dt>Template</dt><dd class="mono">sha256:<?= TemplateRenderer::escape($selectedRecipe->templateSha256) ?></dd></div>
                <div><dt>Task context</dt><dd><?= $selectedRecipe->requiresTaskContext() ? 'required' : 'not required' ?></dd></div>
                <div><dt>Mutation authority</dt><dd><?= $selectedRecipe->requiresMutationAuthority() ? 'required' : 'not required' ?></dd></div>
            </dl>

            <?php foreach ($selectedRecipe->arguments as $argument): ?>
                <?php $value = $workbench->argumentValues[$argument->name] ?? ''; ?>
                <label for="arg-<?= TemplateRenderer::escape($argument->name) ?>"><strong><?= TemplateRenderer::escape($argument->name) ?></strong><?= $argument->required ? ' · required' : '' ?></label>
                <?php if ($argument->type === OperatingPromptArgument::TYPE_BOOLEAN): ?>
                    <select id="arg-<?= TemplateRenderer::escape($argument->name) ?>" name="arg_<?= TemplateRenderer::escape($argument->name) ?>"<?= $argument->required ? ' required' : '' ?>>
                        <option value="">Choose…</option>
                        <option value="true"<?= $value === 'true' ? ' selected' : '' ?>>true</option>
                        <option value="false"<?= $value === 'false' ? ' selected' : '' ?>>false</option>
                    </select>
                <?php elseif ($argument->type === OperatingPromptArgument::TYPE_INTEGER): ?>
                    <input id="arg-<?= TemplateRenderer::escape($argument->name) ?>" name="arg_<?= TemplateRenderer::escape($argument->name) ?>" type="number" value="<?= TemplateRenderer::escape($value) ?>"<?= $argument->minimum !== null ? ' min="' . $argument->minimum . '"' : '' ?><?= $argument->maximum !== null ? ' max="' . $argument->maximum . '"' : '' ?><?= $argument->required ? ' required' : '' ?>>
                <?php else: ?>
                    <input id="arg-<?= TemplateRenderer::escape($argument->name) ?>" name="arg_<?= TemplateRenderer::escape($argument->name) ?>" type="text" value="<?= TemplateRenderer::escape($value) ?>"<?= $argument->required ? ' required' : '' ?>>
                <?php endif; ?>
                <p class="small muted"><?= TemplateRenderer::escape($argument->description) ?><?php if ($argument->examples !== []): ?> Example: <code><?= TemplateRenderer::escape((string) $argument->examples[0]) ?></code>.<?php endif; ?></p>
            <?php endforeach; ?>

            <?php if ($selectedRecipe->allowsAdditionalInstruction()): ?>
                <label for="additional-instruction"><strong>Additional developer instruction</strong> · optional</label>
                <textarea id="additional-instruction" name="additional_instruction" rows="4"><?= TemplateRenderer::escape($workbench->additionalInstruction) ?></textarea>
            <?php endif; ?>

            <button type="submit" name="action" value="generate">Generate deterministic prompt</button>
        <?php endif; ?>
    </form>
</section>

<?php if ($workbench->composition !== null): ?>
<?php $composition = $workbench->composition; $workflow = $composition->workflow; ?>
<p class="eyebrow">Generated prompt</p>
<section class="panel panel--accent">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($workflow->state ?? 'start')) ?>"><?= TemplateRenderer::escape(Presentation::label($workflow->state ?? 'start')) ?></span>
        <?php if ($workflow->nextActionKind !== null): ?><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($workflow->nextActionKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($workflow->nextActionKind)) ?></span><?php endif; ?>
    </div>
    <div class="codeblock">
        <pre id="workbench-prompt"><?= TemplateRenderer::escape($composition->prompt) ?></pre>
        <button type="button" class="copy" data-copy-target="workbench-prompt">Copy prompt</button>
    </div>
</section>

<p class="eyebrow">Provenance</p>
<section class="panel">
    <dl class="facts">
        <div><dt>Prompt bytes</dt><dd class="mono">sha256:<?= TemplateRenderer::escape($composition->promptDigest) ?></dd></div>
        <div><dt>Composition</dt><dd class="mono">sha256:<?= TemplateRenderer::escape($composition->compositionDigest) ?></dd></div>
        <div><dt>Workflow envelope</dt><dd class="mono">sha256:<?= TemplateRenderer::escape($workflow->digest) ?></dd></div>
        <div><dt>Recipe</dt><dd><?= TemplateRenderer::escape($composition->recipe->id) ?></dd></div>
        <div><dt>Recipe template</dt><dd class="mono">sha256:<?= TemplateRenderer::escape($composition->recipe->templateSha256) ?></dd></div>
        <div><dt>Run</dt><dd><?= TemplateRenderer::escape($workflow->runId ?? 'not yet created') ?></dd></div>
        <div><dt>Contract revision</dt><dd><?= $workflow->contractRevision === null ? 'not available' : $workflow->contractRevision ?></dd></div>
        <div><dt>Recall compilation</dt><dd><?= TemplateRenderer::escape($workflow->recallCompilationId ?? 'not available') ?></dd></div>
        <div><dt>Recall bundle</dt><dd class="mono"><?= TemplateRenderer::escape($workflow->recallBundleSha256 ?? 'not available') ?></dd></div>
        <div><dt>Mutation authority</dt><dd><?= $workflow->mutationAllowed ? 'allowed by current owner state' : 'not granted by current owner state' ?></dd></div>
        <?php if ($composition->context !== null): ?><div><dt>Context projection</dt><dd><?= TemplateRenderer::escape($composition->context->status) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($workflow->nextAction !== null): ?>
        <h2>Canonical next action</h2>
        <div class="codeblock"><pre id="workbench-next"><?= TemplateRenderer::escape($workflow->nextAction) ?></pre><button type="button" class="copy" data-copy-target="workbench-next">Copy</button></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
