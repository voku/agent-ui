<?php
use voku\AgentLoop\Workflow\Transparency\TaskTransparencyProjection;
use voku\AgentUi\Integration\AgentKanban\CardSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{card: CardSnapshot, transparency: TaskTransparencyProjection} $model */
$card = $model['card'];
$transparency = $model['transparency'];
$contract = $transparency->contract;
$observation = $transparency->observation;
$scope = $transparency->scopeCoverage;
$implementation = $transparency->implementation;
$review = $transparency->review;
$title = $card->id . ' · Work & review · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($card->id) ?>"><?= TemplateRenderer::escape($card->id) ?></a><span>/</span>Work &amp; review</p>
<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($card->id) ?></span>
    <h1>Work, scope &amp; review</h1>
    <p class="lede">Approved scope, current Git observation and the exact persisted review are shown side by side without turning repository activity into workflow truth.</p>
</div>

<p class="eyebrow">Approved Contract boundary</p>
<section class="panel<?= $contract->exists && !$contract->isApproved() ? ' panel--attention' : '' ?>">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($contract->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($contract->status ?? 'missing')) ?></span>
        <?php if ($contract->revision !== null): ?><span class="small faint">revision <?= $contract->revision ?></span><?php endif; ?>
    </div>
    <?php if (!$contract->exists): ?>
        <p class="empty">No durable Contract exists. There is no approved scope to compare repository changes against.</p>
    <?php else: ?>
        <dl class="kv" style="margin-top:12px">
            <dt>Goal</dt><dd><?= TemplateRenderer::escape($contract->goal ?? 'not recorded') ?></dd>
            <dt>Base commit</dt><dd><code><?= TemplateRenderer::escape($contract->baseCommit ?? 'not recorded') ?></code></dd>
            <dt>Approved by</dt><dd><?= TemplateRenderer::escape($contract->approvedBy ?? 'not approved') ?></dd>
            <dt>Approved at</dt><dd><?= TemplateRenderer::escape($contract->approvedAt ?? 'not approved') ?></dd>
        </dl>
        <div class="grid" style="margin-top:14px">
            <div>
                <h2>In scope</h2>
                <?php if ($contract->scope === []): ?><p class="empty">No paths declared.</p><?php else: ?>
                    <div class="stack"><?php foreach ($contract->scope as $path): ?><code><?= TemplateRenderer::escape($path) ?></code><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            <div>
                <h2>Explicit non-goals</h2>
                <?php if ($contract->nonGoals === []): ?><p class="empty">None recorded.</p><?php else: ?>
                    <div class="stack"><?php foreach ($contract->nonGoals as $nonGoal): ?><p class="small" style="margin:0"><?= TemplateRenderer::escape($nonGoal) ?></p><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            <div>
                <h2>Acceptance criteria</h2>
                <?php if ($contract->acceptanceCriteria === []): ?><p class="empty">None recorded.</p><?php else: ?>
                    <div class="stack"><?php foreach ($contract->acceptanceCriteria as $criterion): ?><p class="small" style="margin:0"><?= TemplateRenderer::escape($criterion) ?></p><?php endforeach; ?></div>
                <?php endif; ?>
                <p class="note">These are required outcomes, not evidence that the outcomes were reached.</p>
            </div>
        </div>
    <?php endif; ?>
</section>

<p class="eyebrow">Repository observation</p>
<section class="panel<?= !$observation->isObserved() ? ' panel--attention' : ($scope->changedOutsideScope !== [] ? ' panel--danger' : '') ?>">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($observation->status->value)) ?>"><?= TemplateRenderer::escape(Presentation::label($observation->status->value)) ?></span>
        <?php if ($observation->isObserved()): ?>
            <span class="small faint"><?= count($observation->changedFiles) ?> changed path(s) observed</span>
        <?php endif; ?>
    </div>
    <?php if (!$observation->isObserved()): ?>
        <p class="muted"><?= TemplateRenderer::escape($observation->unavailableReason ?? 'Repository observation is unavailable.') ?></p>
        <p class="note">Unavailable observation is not rendered as a clean working tree.</p>
    <?php else: ?>
        <dl class="kv" style="margin-top:12px">
            <dt>Contract base</dt><dd><code><?= TemplateRenderer::escape($observation->baseCommit ?? 'unknown') ?></code></dd>
            <dt>Current HEAD</dt><dd><code><?= TemplateRenderer::escape($observation->headCommit ?? 'unborn / unavailable') ?></code></dd>
            <dt>Committed</dt><dd><?= count($observation->committed) ?></dd>
            <dt>Staged</dt><dd><?= count($observation->staged) ?></dd>
            <dt>Unstaged</dt><dd><?= count($observation->unstaged) ?></dd>
            <dt>Untracked</dt><dd><?= count($observation->untracked) ?></dd>
        </dl>
        <div class="split" style="margin-top:16px">
            <div>
                <p class="provenance provenance--observation">changed inside approved scope</p>
                <?php if ($scope->changedInScope === []): ?><p class="empty">No changed path observed in scope.</p><?php else: ?>
                    <div class="stack"><?php foreach ($scope->changedInScope as $path): ?><code><?= TemplateRenderer::escape($path) ?></code><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
            <div>
                <p class="provenance provenance--observation">changed outside approved scope</p>
                <?php if ($scope->changedOutsideScope === []): ?><p class="empty">No outside-scope change observed.</p><?php else: ?>
                    <div class="stack"><?php foreach ($scope->changedOutsideScope as $path): ?><code><?= TemplateRenderer::escape($path) ?></code><?php endforeach; ?></div>
                    <p class="note">This is scope-drift observation. agent-ui does not decide whether it is acceptable.</p>
                <?php endif; ?>
            </div>
        </div>
        <details class="raw" style="margin-top:16px">
            <summary>Git observation by source</summary>
            <div class="grid">
                <?php foreach ([
                    'Committed since base' => $observation->committed,
                    'Staged' => $observation->staged,
                    'Unstaged' => $observation->unstaged,
                    'Untracked' => $observation->untracked,
                ] as $label => $paths): ?>
                    <section class="panel">
                        <h2><?= TemplateRenderer::escape($label) ?></h2>
                        <?php if ($paths === []): ?><p class="empty">None observed.</p><?php else: ?>
                            <div class="stack"><?php foreach ($paths as $path): ?><code><?= TemplateRenderer::escape($path) ?></code><?php endforeach; ?></div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
    <p class="note">Git observation answers what changed. It does not prove implementation completeness or acceptance.</p>
</section>

<p class="eyebrow">Implementation identity</p>
<section class="panel">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($implementation->status->value)) ?>"><?= TemplateRenderer::escape(Presentation::label($implementation->status->value)) ?></span>
        <?php if ($implementation->contractRevision !== null): ?><span class="small faint">Contract rev <?= $implementation->contractRevision ?></span><?php endif; ?>
    </div>
    <?php if ($implementation->digest === null): ?>
        <p class="muted"><?= TemplateRenderer::escape($implementation->reason ?? 'No implementation identity is available.') ?></p>
    <?php else: ?>
        <dl class="kv" style="margin-top:12px">
            <dt>Snapshot digest</dt><dd><code><?= TemplateRenderer::escape($implementation->digest) ?></code></dd>
            <dt>Files in approved scope</dt><dd><?= count($implementation->files) ?></dd>
        </dl>
        <?php if ($implementation->files !== []): ?>
            <details class="raw" style="margin-top:12px"><summary>Exact scoped file hashes</summary>
                <div class="table-scroll"><table class="table"><thead><tr><th>Path</th><th>SHA-256</th></tr></thead><tbody>
                <?php foreach ($implementation->files as $file): ?>
                    <tr><td><code><?= TemplateRenderer::escape($file['path']) ?></code></td><td class="mono small"><?= TemplateRenderer::escape($file['sha256']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </details>
        <?php endif; ?>
    <?php endif; ?>
    <p class="note">Review currency can bind to this identity. “Unavailable” and an empty captured scope remain different states.</p>
</section>

<p class="eyebrow">Exact review report</p>
<section class="panel<?= $review->invalid || $review->currency->value === 'stale' ? ' panel--danger' : '' ?>">
    <div class="action__head">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($review->currency->value)) ?>"><?= TemplateRenderer::escape(Presentation::label($review->currency->value)) ?></span>
        <span class="small faint"><?= count($review->findings) ?> finding(s)</span>
    </div>
    <?php if (!$review->exists): ?>
        <p class="empty">No persisted review report exists for this task.</p>
    <?php elseif ($review->invalid): ?>
        <p class="muted">The persisted review exists but the owner could not validate its shape. No verdict is inferred.</p>
    <?php else: ?>
        <dl class="kv" style="margin-top:12px">
            <dt>Lifecycle status</dt><dd><?= TemplateRenderer::escape(Presentation::label($review->lifecycleStatus)) ?></dd>
            <dt>Report verdict</dt><dd><?= TemplateRenderer::escape(Presentation::label($review->reportStatus)) ?></dd>
            <dt>Contract revision</dt><dd><?= TemplateRenderer::escape($review->contractRevision === null ? 'not recorded' : (string) $review->contractRevision) ?></dd>
            <dt>Implementation snapshot</dt><dd><code><?= TemplateRenderer::escape($review->implementationSnapshot ?? 'not recorded') ?></code></dd>
            <dt>Report SHA-256</dt><dd><code><?= TemplateRenderer::escape($review->sha256 ?? 'not recorded') ?></code></dd>
            <dt>Acknowledged by</dt><dd><?= TemplateRenderer::escape($review->acknowledgedBy ?? 'not acknowledged') ?></dd>
            <dt>Acknowledged at</dt><dd><?= TemplateRenderer::escape($review->acknowledgedAt ?? 'not acknowledged') ?></dd>
        </dl>
    <?php endif; ?>
    <p class="note">The report verdict and its lifecycle acknowledgement are intentionally separate. Neither becomes workflow authority merely because it is green.</p>
</section>

<?php if ($review->findings !== []): ?>
    <p class="eyebrow">Review findings</p>
    <section class="stack">
        <?php foreach ($review->findings as $finding): ?>
            <article class="panel<?= strtolower($finding->severity) === 'fail' ? ' panel--danger' : '' ?>">
                <div class="action__head">
                    <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($finding->severity)) ?>"><?= TemplateRenderer::escape($finding->severity) ?></span>
                    <strong class="mono"><?= TemplateRenderer::escape($finding->id) ?></strong>
                </div>
                <p><?= TemplateRenderer::escape($finding->message) ?></p>
                <?php if ($finding->evidence !== []): ?>
                    <p class="note">Evidence</p>
                    <div class="stack"><?php foreach ($finding->evidence as $evidence): ?><code><?= TemplateRenderer::escape($evidence) ?></code><?php endforeach; ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($transparency->blocked !== null): ?>
    <p class="eyebrow">Explicit blocker</p>
    <section class="panel panel--danger">
        <div class="action__head"><span class="pill pill--blocked"><?= TemplateRenderer::escape($transparency->blocked->state) ?></span><strong>Durable blocked/rejected record</strong></div>
        <dl class="kv" style="margin-top:12px">
            <dt>Reason</dt><dd><?= TemplateRenderer::escape($transparency->blocked->reason ?? 'not recorded') ?></dd>
            <dt>Affected constraint</dt><dd><?= TemplateRenderer::escape($transparency->blocked->affectedConstraint ?? 'not recorded') ?></dd>
            <dt>Minimum Contract change</dt><dd><?= TemplateRenderer::escape($transparency->blocked->minimumContractChange ?? 'not recorded') ?></dd>
        </dl>
        <?php if ($transparency->blocked->evidence !== []): ?><div class="stack" style="margin-top:12px"><?php foreach ($transparency->blocked->evidence as $evidence): ?><code><?= TemplateRenderer::escape($evidence) ?></code><?php endforeach; ?></div><?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($transparency->deferredFollowUp !== null): ?>
    <p class="eyebrow">Explicitly deferred follow-up</p>
    <section class="panel panel--attention">
        <dl class="kv">
            <dt>Follow-up</dt><dd><code><?= TemplateRenderer::escape($transparency->deferredFollowUp->followUpRef) ?></code></dd>
            <dt>Reason</dt><dd><?= TemplateRenderer::escape($transparency->deferredFollowUp->reason) ?></dd>
            <dt>Decided by</dt><dd><?= TemplateRenderer::escape($transparency->deferredFollowUp->decidedBy) ?></dd>
            <dt>Decided at</dt><dd><?= TemplateRenderer::escape($transparency->deferredFollowUp->decidedAt) ?></dd>
        </dl>
        <p class="note">Only an explicit durable Learning decision appears here. Missing work is never guessed to be deferred.</p>
    </section>
<?php endif; ?>

<?php $taskNavId = $card->id; $taskNavCurrent = '/work'; require __DIR__ . '/../layout/task-nav.php'; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
