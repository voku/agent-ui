<?php
use voku\AgentUi\Feature\PromptWorkbench\PromptWorkbenchViewModel;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/*
 * The generated prompt, or why none was generated.
 *
 * Rendered inside the full page and, on its own, as the fragment the live
 * preview swaps in. Both come from the same owner calls, so a preview is the
 * exact bytes Generate would produce, never an approximation built in the
 * browser.
 */

/** @var array{workbench: PromptWorkbenchViewModel} $model */
$workbench ??= $model['workbench'];
?>
<?php if ($workbench->errors !== []): ?>
<section class="panel panel--attention" aria-labelledby="prompt-errors">
    <h2 id="prompt-errors">Prompt not generated</h2>
    <ul class="workbench__errors">
        <?php foreach ($workbench->errors as $error): ?><li><?= TemplateRenderer::escape($error) ?></li><?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($workbench->composition !== null): ?>
<?php $composition = $workbench->composition; $workflow = $composition->workflow; ?>
<section class="panel panel--accent" aria-labelledby="generated-prompt">
    <div class="action__head">
        <h2 id="generated-prompt" style="margin:0">Generated prompt</h2>
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($workflow->state ?? 'start')) ?>"><?= TemplateRenderer::escape(Presentation::label($workflow->state ?? 'start')) ?></span>
        <?php if ($workflow->nextActionKind !== null): ?><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($workflow->nextActionKind)) ?>"><?= TemplateRenderer::escape(Presentation::label($workflow->nextActionKind)) ?></span><?php endif; ?>
    </div>
    <div class="codeblock">
        <pre id="workbench-prompt" class="workbench__prompt"><?= TemplateRenderer::escape($composition->prompt) ?></pre>
        <button type="button" class="copy" hidden data-copy-target="workbench-prompt">Copy prompt</button>
    </div>

    <h2 style="margin-top:18px">Provenance</h2>
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
        <h2 style="margin-top:18px">Canonical next action</h2>
        <div class="codeblock"><pre id="workbench-next"><?= TemplateRenderer::escape($workflow->nextAction) ?></pre><button type="button" class="copy" hidden data-copy-target="workbench-next">Copy</button></div>
        <?php $cmdRef = Presentation::commandReference($workflow->nextAction); ?>
        <?php if ($cmdRef !== null): ?>
            <p class="small note" style="margin-top:6px"><a href="/commands#<?= TemplateRenderer::escape($cmdRef) ?>">View command reference: <code><?= TemplateRenderer::escape($cmdRef) ?></code> →</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
