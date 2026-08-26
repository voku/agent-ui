<?php
use voku\AgentLoop\Init\ManagedAssetChangePlan;
use voku\AgentLoop\Init\RepositorySetupOperation;
use voku\AgentLoop\Init\RepositorySetupProjection;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{hosts: array<string, array{projection?: RepositorySetupProjection, legal?: list<RepositorySetupOperation>, install?: ManagedAssetChangePlan, remove?: ManagedAssetChangePlan, error?: string}>, csrf: string} $model */
$hosts = $model['hosts'];
$csrf = $model['csrf'];
$title = 'Setup · agent-ui';
$nav = 'setup';
$projectLabel = 'repository setup';
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <h1>Repository setup</h1>
    <p class="lede">These states and legal actions come from <code>agent-loop</code>. The UI does not infer setup authority or parse manifests.</p>
</div>

<div class="grid">
<?php foreach ($hosts as $host => $entry): ?>
    <section class="panel">
        <h2><?= TemplateRenderer::escape(ucfirst($host)) ?></h2>
        <?php if (isset($entry['error'])): ?>
            <p class="pill pill--attention">Unavailable</p>
            <p class="small muted"><?= TemplateRenderer::escape($entry['error']) ?></p>
            <?php continue; ?>
        <?php endif; ?>
        <?php $projection = $entry['projection']; $integration = $projection->integration; $legal = $entry['legal']; ?>
        <p><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($projection->runtime?->status->value ?? 'unavailable')) ?>"><?= TemplateRenderer::escape($projection->runtime?->status->value ?? 'unavailable') ?></span></p>
        <dl class="facts">
            <div><dt>Selection</dt><dd><?= TemplateRenderer::escape($projection->selection->value) ?></dd></div>
            <div><dt>Instructions</dt><dd><?= TemplateRenderer::escape($integration?->instructions->value ?? 'unknown') ?></dd></div>
            <div><dt>Skills</dt><dd><?= TemplateRenderer::escape($integration?->skills->value ?? 'unknown') ?></dd></div>
            <div><dt>Subagents</dt><dd><?= TemplateRenderer::escape($integration?->subagents->value ?? 'unknown') ?></dd></div>
            <div><dt>Policy</dt><dd><?= TemplateRenderer::escape($integration?->policy->value ?? 'unknown') ?></dd></div>
            <div><dt>Git integration</dt><dd><?= TemplateRenderer::escape($integration?->gitIntegration->value ?? 'unknown') ?></dd></div>
        </dl>
        <?php if ($projection->runtimeBoundary !== null): ?>
            <p class="note"><strong>Host/user action:</strong> <?= TemplateRenderer::escape($projection->runtimeBoundary) ?></p>
        <?php endif; ?>
        <?php if ($projection->nextAction !== null): ?>
            <p class="note"><strong>Owner next action:</strong> <?= TemplateRenderer::escape($projection->nextAction) ?></p>
        <?php endif; ?>

        <?php if (in_array(RepositorySetupOperation::INSTALL_ASSETS, $legal, true) || in_array(RepositorySetupOperation::UPDATE_ASSETS, $legal, true)): ?>
            <?php $plan = $entry['install']; ?>
            <details>
                <summary>Review install/update plan (<?= count($plan->operations) ?> changes, <?= count($plan->blocked) ?> blocked)</summary>
                <ul class="small">
                    <?php foreach ($plan->operations as $operation): ?><li><?= TemplateRenderer::escape($operation->operation->value . ' · ' . $operation->kind->value . ' · ' . $operation->entry) ?></li><?php endforeach; ?>
                    <?php foreach ($plan->blocked as $operation): ?><li><strong>blocked:</strong> <?= TemplateRenderer::escape($operation->kind->value . ' · ' . $operation->entry . ' · ' . $operation->reason) ?></li><?php endforeach; ?>
                </ul>
            </details>
            <form method="post" action="/setup/<?= TemplateRenderer::escape($host) ?>/install">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <input type="hidden" name="plan_id" value="<?= TemplateRenderer::escape($plan->planId()) ?>">
                <input type="hidden" name="expected_state" value="<?= TemplateRenderer::escape($plan->expectedState->value) ?>">
                <button type="submit">Install / update managed assets</button>
            </form>
        <?php endif; ?>

        <?php if (in_array(RepositorySetupOperation::SYNC_POLICY, $legal, true)): ?>
            <form method="post" action="/setup/<?= TemplateRenderer::escape($host) ?>/sync-policy">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <button type="submit">Sync repository policy</button>
            </form>
        <?php endif; ?>

        <?php if (in_array(RepositorySetupOperation::REMOVE_ASSETS, $legal, true)): ?>
            <?php $plan = $entry['remove']; ?>
            <details>
                <summary>Review removal plan (<?= count($plan->operations) ?> removals, <?= count($plan->blocked) ?> protected)</summary>
                <p class="small muted">Only package-managed unchanged assets are removable. Locally modified or unverifiable managed assets remain blocked; project-owned files and host/user settings stay untouched.</p>
                <ul class="small">
                    <?php foreach ($plan->operations as $operation): ?><li><?= TemplateRenderer::escape($operation->operation->value . ' · ' . $operation->kind->value . ' · ' . $operation->entry) ?></li><?php endforeach; ?>
                    <?php foreach ($plan->blocked as $operation): ?><li><strong>protected:</strong> <?= TemplateRenderer::escape($operation->kind->value . ' · ' . $operation->entry . ' · ' . $operation->reason) ?></li><?php endforeach; ?>
                </ul>
            </details>
            <form method="post" action="/setup/<?= TemplateRenderer::escape($host) ?>/remove">
                <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
                <input type="hidden" name="plan_id" value="<?= TemplateRenderer::escape($plan->planId()) ?>">
                <input type="hidden" name="expected_state" value="<?= TemplateRenderer::escape($plan->expectedState->value) ?>">
                <button type="submit">Remove managed assets</button>
            </form>
        <?php endif; ?>

        <?php if (in_array(RepositorySetupOperation::REVIEW_CONFLICT, $legal, true)): ?>
            <p class="note"><strong>Review required:</strong> the owner reports a setup conflict; no force/adopt action is invented here.</p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>

<?php
$canSyncGit = false;
foreach ($hosts as $entry) {
    foreach ($entry['legal'] ?? [] as $operation) {
        if ($operation === RepositorySetupOperation::SYNC_GIT_INTEGRATION) {
            $canSyncGit = true;
        }
    }
}
?>
<?php if ($canSyncGit): ?>
<p class="eyebrow">Repository Git integration</p>
<section class="panel panel--accent">
    <h2>Declared local Git integration</h2>
    <p class="muted">Applies only repository-declared hooks/commit-template integration through the owner API.</p>
    <form method="post" action="/setup/sync-git">
        <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($csrf) ?>">
        <button type="submit">Sync Git integration</button>
    </form>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
