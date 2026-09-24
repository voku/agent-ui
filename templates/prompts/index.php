<?php
use voku\AgentRecallCompiler\OperatingPromptArgument;
use voku\AgentRecallCompiler\OperatingPromptRecipe;
use voku\AgentUi\Feature\PromptWorkbench\PromptWorkbenchViewModel;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{workbench: PromptWorkbenchViewModel} $model */
$workbench = $model['workbench'];
$taskContext = $model['task_context'] ?? null;
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
<?php if ($taskContext !== null): ?>
<?php $taskNavCurrent = '/prompts'; require __DIR__ . '/../layout/task-context.php'; ?>
<?php endif; ?>
<div class="page-head">
    <h1>Prompt Workbench</h1>
    <p class="lede">Choose what you are trying to do, then compose a workflow-owned envelope with the explicitly selected Recall recipe. The UI owns presentation and deterministic composition, not workflow authority or recipe semantics.</p>
</div>

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

<?php
/*
 * One form, two columns: the owner's recipe catalog on the left, and the chosen
 * recipe's owner-declared inputs plus the generated prompt on the right. The
 * right column stays in view, so choosing, filling in and reading the result
 * no longer means a round trip that lands back at the top of a long page.
 *
 * Without JavaScript the page still works as before: "Load selected recipe"
 * reveals the recipe's fields, "Generate" renders the result, and each submit
 * targets a fragment so the browser lands on what changed, not on the header.
 * With JavaScript every recipe's fieldset is already here (disabled, so only
 * the selected recipe's values are submitted) and the result is refreshed in
 * place through the same POST endpoint.
 */
?>
<form class="workbench" method="post" action="<?= TemplateRenderer::escape($formAction) ?>" data-prompt-workbench>
    <div class="workbench__catalog">
        <p class="eyebrow">Inputs</p>
        <section class="panel">
            <?php if (!$workbench->taskAware): ?>
                <div class="form__row">
                    <label class="field" for="task-id"><span>Task ID</span>
                        <input id="task-id" name="task_id" type="text" required value="<?= TemplateRenderer::escape($workbench->taskId ?? '') ?>" placeholder="ITPNG-346">
                    </label>
                </div>
                <div class="form__row" style="margin-top:10px">
                    <label class="field field--wide" for="goal"><span>Goal</span>
                        <textarea id="goal" name="goal" rows="4" required><?= TemplateRenderer::escape($workbench->goal) ?></textarea>
                    </label>
                </div>
            <?php else: ?>
                <p style="margin:0"><strong>Task:</strong> <?= TemplateRenderer::escape($workbench->taskId ?? '') ?></p>
                <?php if ($workbench->taskTitle !== null): ?><p class="muted" style="margin:4px 0 0"><?= TemplateRenderer::escape($workbench->taskTitle) ?></p><?php endif; ?>
            <?php endif; ?>
        </section>

        <p class="eyebrow">What do you need now?</p>
        <p class="small muted" style="margin:-4px 0 12px">Every choice below comes from agent-recall-compiler. agent-ui groups the typed owner metadata for presentation; it does not rank, auto-select, or infer a recipe from your text.</p>

        <?php foreach ($workbench->recipeGroups() as $group): ?>
            <p class="workbench__group"><?= TemplateRenderer::escape($group->title) ?></p>
            <div class="recipe-list">
                <?php foreach ($group->recipes as $recipe): ?>
                    <?php
                    $requiredArguments = array_values(array_filter(
                        $recipe->arguments,
                        static fn (OperatingPromptArgument $argument): bool => $argument->required,
                    ));
                    ?>
                    <label class="recipe">
                        <input class="recipe__radio" type="radio" name="recipe" required value="<?= TemplateRenderer::escape($recipe->id) ?>"<?= $recipe->id === $workbench->selectedRecipeId ? ' checked' : '' ?>>
                        <span class="recipe__body">
                            <strong class="recipe__title"><?= TemplateRenderer::escape($recipe->title) ?></strong>
                            <span class="recipe__description"><?= TemplateRenderer::escape($recipe->description) ?></span>
                            <span class="recipe__meta">
                                <span class="pill">L<?= $recipe->level ?></span>
                                <?php if ($requiredArguments !== []): ?><span class="pill"><?= count($requiredArguments) ?> required field<?= count($requiredArguments) === 1 ? '' : 's' ?></span><?php endif; ?>
                                <?php if ($recipe->requiresTaskContext()): ?><span class="pill">task context required</span><?php endif; ?>
                                <?php if ($recipe->requiresMutationAuthority()): ?><span class="pill">mutation authority required</span><?php endif; ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="btn-row" data-prompt-select-row>
            <button class="btn" type="submit" name="action" value="select" formaction="<?= TemplateRenderer::escape($formAction) ?>#recipe-fields">Load selected recipe</button>
        </div>
    </div>

    <aside class="workbench__side" id="recipe-fields" aria-label="Selected recipe and generated prompt">
        <section class="panel workbench__recipe">
            <p class="workbench__empty" data-prompt-empty<?= $selectedRecipe instanceof OperatingPromptRecipe ? ' hidden' : '' ?>>Choose a recipe to see the inputs it declares.</p>
            <?php foreach ($workbench->recipes as $recipe): ?>
                <?php
                $isSelected = $recipe->id === $workbench->selectedRecipeId;
                $fieldPrefix = 'arg-' . $recipe->id . '-';
                ?>
                <fieldset class="recipe-fields" data-recipe-fields="<?= TemplateRenderer::escape($recipe->id) ?>"<?= $isSelected ? '' : ' hidden disabled' ?>>
                    <legend class="recipe-fields__title"><?= TemplateRenderer::escape($recipe->title) ?></legend>
                    <p class="small muted" style="margin:0"><?= TemplateRenderer::escape($recipe->description) ?></p>
                    <dl class="facts">
                        <div><dt>Recipe</dt><dd class="mono"><?= TemplateRenderer::escape($recipe->id) ?></dd></div>
                        <div><dt>Purpose</dt><dd><?= TemplateRenderer::escape($recipe->purpose) ?></dd></div>
                        <div><dt>Level</dt><dd>L<?= $recipe->level ?></dd></div>
                        <div><dt>Template</dt><dd class="mono">sha256:<?= TemplateRenderer::escape($recipe->templateSha256) ?></dd></div>
                        <div><dt>Task context</dt><dd><?= $recipe->requiresTaskContext() ? 'required' : 'not required' ?></dd></div>
                        <div><dt>Mutation authority</dt><dd><?= $recipe->requiresMutationAuthority() ? 'required' : 'not required' ?></dd></div>
                    </dl>

                    <?php foreach ($recipe->arguments as $argument): ?>
                        <?php
                        $value = $isSelected ? ($workbench->argumentValues[$argument->name] ?? '') : '';
                        $fieldId = $fieldPrefix . $argument->name;
                        ?>
                        <div class="form__row" style="margin-top:12px">
                        <label class="field field--wide" for="<?= TemplateRenderer::escape($fieldId) ?>"><span><?= TemplateRenderer::escape($argument->name) ?><?= $argument->required ? ' · required' : '' ?></span>
                        <?php if ($argument->type === OperatingPromptArgument::TYPE_BOOLEAN): ?>
                            <select id="<?= TemplateRenderer::escape($fieldId) ?>" name="arg_<?= TemplateRenderer::escape($argument->name) ?>"<?= $argument->required ? ' required' : '' ?>>
                                <option value="">Choose…</option>
                                <option value="true"<?= $value === 'true' ? ' selected' : '' ?>>true</option>
                                <option value="false"<?= $value === 'false' ? ' selected' : '' ?>>false</option>
                            </select>
                        <?php elseif ($argument->type === OperatingPromptArgument::TYPE_INTEGER): ?>
                            <input id="<?= TemplateRenderer::escape($fieldId) ?>" name="arg_<?= TemplateRenderer::escape($argument->name) ?>" type="number" value="<?= TemplateRenderer::escape($value) ?>"<?= $argument->minimum !== null ? ' min="' . $argument->minimum . '"' : '' ?><?= $argument->maximum !== null ? ' max="' . $argument->maximum . '"' : '' ?><?= $argument->required ? ' required' : '' ?>>
                        <?php else: ?>
                            <input id="<?= TemplateRenderer::escape($fieldId) ?>" name="arg_<?= TemplateRenderer::escape($argument->name) ?>" type="text" value="<?= TemplateRenderer::escape($value) ?>"<?= $argument->required ? ' required' : '' ?>>
                        <?php endif; ?>
                        </label>
                        </div>
                        <p class="small muted" style="margin:4px 0 0"><?= TemplateRenderer::escape($argument->description) ?><?php if ($argument->examples !== []): ?> Example: <code><?= TemplateRenderer::escape((string) $argument->examples[0]) ?></code>.<?php endif; ?></p>
                    <?php endforeach; ?>

                    <?php if ($recipe->allowsAdditionalInstruction()): ?>
                        <div class="form__row" style="margin-top:12px">
                            <label class="field field--wide" for="<?= TemplateRenderer::escape($fieldPrefix) ?>additional-instruction"><span>Additional developer instruction · optional</span>
                                <textarea id="<?= TemplateRenderer::escape($fieldPrefix) ?>additional-instruction" name="additional_instruction" rows="3"><?= TemplateRenderer::escape($isSelected ? $workbench->additionalInstruction : '') ?></textarea>
                            </label>
                        </div>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>

            <div class="btn-row workbench__actions" data-prompt-generate-row<?= $selectedRecipe instanceof OperatingPromptRecipe ? '' : ' hidden' ?>>
                <button class="btn btn--primary" type="submit" name="action" value="generate" formaction="<?= TemplateRenderer::escape($formAction) ?>#prompt-result">Generate deterministic prompt</button>
                <span class="small faint" data-prompt-status role="status" aria-live="polite"></span>
            </div>
        </section>

        <div class="workbench__result" id="prompt-result" data-prompt-result tabindex="-1">
            <?php require __DIR__ . '/_result.php'; ?>
        </div>
    </aside>
</form>

<?php require __DIR__ . '/../layout/footer.php'; ?>
