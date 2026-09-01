<?php
use voku\AgentLoop\Workflow\Transparency\ContextCoverage;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\Integration\AgentRecallCompiler\ContextExplanationSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, context: ContextExplanationSnapshot, coverage: ContextCoverage} $model */
$card = $model['card'];
$context = $model['context'];
$coverage = $model['coverage'];
$explanation = $context->explanation;
$title = $card->id . ' · Context & constraints · agent-ui';
$nav = null;
$projectLabel = null;
$selectedGuidance = $explanation === null ? [] : array_values(array_filter(
    $explanation->guidance,
    static fn ($guidance): bool => $guidance->selected,
));
$excludedGuidance = $explanation === null ? [] : array_values(array_filter(
    $explanation->guidance,
    static fn ($guidance): bool => !$guidance->selected,
));
$selectedItems = $explanation === null ? [] : array_values(array_filter(
    $explanation->items,
    static fn ($item): bool => $item->selected,
));
$excludedItems = $explanation === null ? [] : array_values(array_filter(
    $explanation->items,
    static fn ($item): bool => !$item->selected,
));
$interaction = $coverage->interaction->toArray();
$futureWork = $coverage->futureWork->toArray();
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a><span>/</span>Context</p>
<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($card->id) ?></span>
    <h1>Context &amp; constraints</h1>
    <p class="lede">The exact persisted context Recall compiled for this task, plus agent-loop's owner-backed record of skipped and budget-omitted context. Opening this page never recompiles anything.</p>
</div>

<p class="eyebrow">Recall selection</p>
<?php if (!$context->isAvailable()): ?>
    <section class="panel <?= $context->status === ContextExplanationSnapshot::INVALID ? 'panel--danger' : 'panel--attention' ?>">
        <div class="action__head">
            <span class="pill pill--<?= $context->status === ContextExplanationSnapshot::INVALID ? 'blocked' : 'attention' ?>"><?= TemplateRenderer::escape($context->status) ?></span>
            <strong>Persisted context is not currently explainable</strong>
        </div>
        <p class="muted"><?= TemplateRenderer::escape($context->problem ?? 'Context explanation unavailable.') ?></p>
        <p class="note">This is not interpreted as “no constraints” or “nothing was relevant”. The owning Recall state must become readable/current first.</p>
    </section>
<?php else: ?>
    <section class="panel <?= $explanation->hasIntegrityFailures() ? 'panel--danger' : 'panel--accent' ?>">
        <div class="action__head">
            <span class="pill pill--<?= $explanation->hasIntegrityFailures() ? 'blocked' : 'ok' ?>"><?= $explanation->hasIntegrityFailures() ? 'integrity problem' : 'persisted & verified' ?></span>
            <span class="small faint">Recall compilation <span class="mono"><?= TemplateRenderer::escape($explanation->compilationId ?? 'unidentified') ?></span></span>
        </div>
        <dl class="kv" style="margin-top:12px">
            <dt>Bundle SHA-256</dt><dd><code><?= TemplateRenderer::escape($explanation->bundleSha256) ?></code></dd>
            <dt>Constraints</dt><dd><?= count($explanation->constraints) ?></dd>
            <dt>Selected guidance</dt><dd><?= count($selectedGuidance) ?></dd>
            <dt>Excluded guidance</dt><dd><?= count($excludedGuidance) ?></dd>
        </dl>
        <p class="note">Recall selection is derived context. It does not approve scope, validation, review, or workflow transitions.</p>
    </section>

    <?php if ($explanation->integrityFailures !== [] || $explanation->warnings !== []): ?>
        <section class="panel panel--danger stack" style="margin-top:12px">
            <h2>Integrity &amp; warnings</h2>
            <?php foreach ($explanation->integrityFailures as $failure): ?>
                <p style="margin:0"><strong>Integrity:</strong> <?= TemplateRenderer::escape($failure) ?></p>
            <?php endforeach; ?>
            <?php foreach ($explanation->warnings as $warning): ?>
                <p style="margin:0"><strong>Warning:</strong> <?= TemplateRenderer::escape($warning) ?></p>
            <?php endforeach; ?>
            <p class="note">Stale or damaged persisted context remains inspectable here, but never looks current.</p>
        </section>
    <?php endif; ?>
<?php endif; ?>

<p class="eyebrow">Hard constraints</p>
<section class="stack">
    <?php if ($explanation === null || $explanation->constraints === []): ?>
        <div class="panel"><p class="empty">No persisted hard-constraint selection is available for this task.</p></div>
    <?php else: ?>
        <?php foreach ($explanation->constraints as $constraint): ?>
            <article class="panel panel--accent">
                <div class="action__head">
                    <span class="pill pill--attention">hard constraint</span>
                    <strong class="mono"><?= TemplateRenderer::escape($constraint->id) ?></strong>
                </div>
                <dl class="kv" style="margin-top:12px">
                    <dt>Engine</dt><dd><?= TemplateRenderer::escape($constraint->engine) ?></dd>
                    <dt>Rule</dt><dd><code><?= TemplateRenderer::escape($constraint->ruleIdentifier) ?></code></dd>
                    <dt>Source proposal</dt><dd><?= TemplateRenderer::escape($constraint->sourceProposal) ?></dd>
                    <dt>Status</dt><dd><?= TemplateRenderer::escape($constraint->status ?? 'metadata not persisted') ?></dd>
                    <dt>Scope</dt><dd><?= TemplateRenderer::escape($constraint->scope === null ? 'metadata not persisted' : implode(', ', $constraint->scope)) ?></dd>
                    <dt>Validation</dt><dd><?= TemplateRenderer::escape($constraint->validationCommands === null ? 'metadata not persisted' : implode(' · ', $constraint->validationCommands)) ?></dd>
                    <dt>Tags</dt><dd><?= TemplateRenderer::escape($constraint->tags === null ? 'metadata not persisted' : implode(', ', $constraint->tags)) ?></dd>
                </dl>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<p class="eyebrow">Selected guidance</p>
<section class="stack">
    <?php if ($selectedGuidance === []): ?>
        <div class="panel"><p class="empty">No guidance is recorded as selected in the persisted Recall explanation.</p></div>
    <?php else: ?>
        <?php foreach ($selectedGuidance as $guidance): ?>
            <?php $stats = $explanation?->outcomeStats[$guidance->guidanceId] ?? null; ?>
            <article class="panel">
                <div class="action__head">
                    <span class="pill pill--ok"><?= TemplateRenderer::escape($guidance->guidanceType->value) ?></span>
                    <strong class="mono"><?= TemplateRenderer::escape($guidance->guidanceId) ?></strong>
                </div>
                <dl class="kv" style="margin-top:12px">
                    <dt>Why selected</dt><dd><?= TemplateRenderer::escape($guidance->selectionReason?->value ?? 'owner reason unavailable') ?></dd>
                    <dt>Source proposal</dt><dd><?= TemplateRenderer::escape($guidance->sourceProposal ?? 'not recorded') ?></dd>
                    <dt>Task files</dt><dd><?= TemplateRenderer::escape($guidance->taskFiles === [] ? 'none recorded' : implode(', ', $guidance->taskFiles)) ?></dd>
                    <?php if ($stats !== null): ?>
                        <dt>Outcome evidence</dt><dd><?= $stats['selected_count'] ?> selected · <?= $stats['helpful_count'] ?> helpful · <?= $stats['irrelevant_count'] ?> irrelevant · <?= $stats['harmful_count'] ?> harmful · <?= $stats['violation_detected_count'] ?> violations</dd>
                    <?php endif; ?>
                </dl>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<p class="eyebrow">Considered but not selected</p>
<section class="stack">
    <?php if ($excludedGuidance === []): ?>
        <div class="panel"><p class="empty">No excluded guidance decisions are persisted for this task.</p></div>
    <?php else: ?>
        <?php foreach ($excludedGuidance as $guidance): ?>
            <article class="panel">
                <div class="action__head">
                    <span class="pill pill--neutral"><?= TemplateRenderer::escape($guidance->guidanceType->value) ?></span>
                    <strong class="mono"><?= TemplateRenderer::escape($guidance->guidanceId) ?></strong>
                </div>
                <dl class="kv" style="margin-top:12px">
                    <dt>Eligible</dt><dd><?= $guidance->eligible ? 'yes' : 'no' ?></dd>
                    <dt>Why not</dt><dd><?= TemplateRenderer::escape($guidance->exclusionReason?->value ?? 'owner reason unavailable') ?></dd>
                    <dt>Source proposal</dt><dd><?= TemplateRenderer::escape($guidance->sourceProposal ?? 'not recorded') ?></dd>
                    <dt>Task files</dt><dd><?= TemplateRenderer::escape($guidance->taskFiles === [] ? 'none recorded' : implode(', ', $guidance->taskFiles)) ?></dd>
                </dl>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<p class="eyebrow">Context facts</p>
<section class="stack">
    <?php if ($selectedItems === []): ?>
        <div class="panel"><p class="empty">No selected context explanation items are persisted.</p></div>
    <?php else: ?>
        <?php foreach ($selectedItems as $item): ?>
            <article class="panel">
                <div class="action__head">
                    <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($item->state->value)) ?>"><?= TemplateRenderer::escape($item->state->value) ?></span>
                    <strong><?= TemplateRenderer::escape($item->what) ?></strong>
                </div>
                <dl class="kv" style="margin-top:12px">
                    <dt>Kind</dt><dd><?= TemplateRenderer::escape($item->kind) ?></dd>
                    <dt>Why</dt><dd><?= TemplateRenderer::escape($item->why) ?></dd>
                    <dt>How</dt><dd><?= TemplateRenderer::escape($item->how) ?></dd>
                    <dt>Authority</dt><dd><?= TemplateRenderer::escape($item->authority) ?></dd>
                    <dt>Intended use</dt><dd><?= TemplateRenderer::escape($item->use) ?></dd>
                    <dt>Source</dt><dd><?= TemplateRenderer::escape($item->sourceRef ?? 'not recorded') ?></dd>
                    <dt>Evidence</dt><dd><?= TemplateRenderer::escape($item->evidenceIds === [] ? 'none recorded' : implode(', ', $item->evidenceIds)) ?></dd>
                </dl>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<p class="eyebrow">Skipped, omitted &amp; uncertain</p>
<div class="grid">
    <section class="panel">
        <h2>Recall items not selected</h2>
        <?php if ($excludedItems === []): ?>
            <p class="empty">None recorded.</p>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($excludedItems as $item): ?>
                    <div>
                        <strong><?= TemplateRenderer::escape($item->what) ?></strong>
                        <p class="small muted" style="margin:2px 0 0"><?= TemplateRenderer::escape($item->whyNot ?? 'Recall did not persist a why-not reason.') ?></p>
                        <p class="note" style="margin-top:2px">state <?= TemplateRenderer::escape($item->state->value) ?> · kind <?= TemplateRenderer::escape($item->kind) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Workflow context skipped</h2>
        <?php if ($coverage->skipped === []): ?>
            <p class="empty">None recorded.</p>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($coverage->skipped as $skipped): ?>
                    <p class="small" style="margin:0"><?= TemplateRenderer::escape($skipped) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="note">These are agent-loop's recorded missing/invalid inputs, not Recall exclusion decisions.</p>
    </section>

    <section class="panel">
        <h2>Context budget omitted</h2>
        <?php if ($coverage->omitted === []): ?>
            <p class="empty">None recorded.</p>
        <?php else: ?>
            <dl class="kv">
                <?php foreach ($coverage->omitted as $omission): ?>
                    <dt><?= TemplateRenderer::escape($omission->category) ?></dt><dd><?= $omission->count ?> omitted</dd>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
        <p class="note">Budget omission means “not included”, never “not relevant”.</p>
    </section>
</div>

<p class="eyebrow">Context policy</p>
<div class="grid">
    <section class="panel">
        <h2>Human explanation policy</h2>
        <dl class="kv">
            <dt>Mode</dt><dd><?= TemplateRenderer::escape($interaction['human_explanations']) ?></dd>
            <dt>Interactive</dt><dd><?= TemplateRenderer::escape($interaction['interactive_behavior']) ?></dd>
            <dt>Unattended</dt><dd><?= TemplateRenderer::escape($interaction['unattended_behavior']) ?></dd>
            <dt>Authority decisions</dt><dd><?= TemplateRenderer::escape($interaction['authority_bearing_decisions']) ?></dd>
        </dl>
    </section>
    <section class="panel">
        <h2>Future-work policy</h2>
        <dl class="kv">
            <dt>Mode</dt><dd><?= TemplateRenderer::escape($futureWork['mode']) ?></dd>
            <dt>Follow-up ceiling</dt><dd><?= $futureWork['max_follow_up_slices'] ?></dd>
            <dt>Current scope expansion</dt><dd><?= TemplateRenderer::escape($futureWork['current_contract_scope_expansion']) ?></dd>
            <dt>Follow-up authority</dt><dd><?= TemplateRenderer::escape($futureWork['follow_up_authority']) ?></dd>
        </dl>
    </section>
</div>

<?php $taskNavId = $card->id; $taskNavCurrent = '/context'; require __DIR__ . '/../layout/task-nav.php'; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
