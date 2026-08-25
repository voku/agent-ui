<?php
use voku\AgentUi\Integration\AgentLoop\TaskAuditSnapshot;
use voku\AgentUi\Integration\AgentLoop\WorkflowSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;
/** @var array{workflow: WorkflowSnapshot, audit: TaskAuditSnapshot} $model */
$workflow = $model['workflow'];
$audit = $model['audit'];
$title = $workflow->taskId . ' · Evidence · agent-ui';
$nav = null;
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/board">Board</a><span>/</span><a href="/task/<?= TemplateRenderer::escape($workflow->taskId) ?>"><?= TemplateRenderer::escape($workflow->taskId) ?></a><span>/</span>Evidence</p>
<div class="page-head">
    <span class="page-head__id"><?= TemplateRenderer::escape($workflow->taskId) ?></span>
    <h1>Evidence &amp; audit</h1>
    <p class="lede">Every fact below comes from an owner projection or store. Generated evidence stays evidence —
        it is not approval, and it is not workflow authority.</p>
</div>

<p class="eyebrow">Contract</p>
<section class="panel">
    <div class="action__head" style="margin-bottom:12px">
        <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($audit->contractStatus)) ?>"><?= TemplateRenderer::escape(Presentation::label($audit->contractStatus)) ?></span>
        <?php if ($audit->contractRevision !== null): ?>
            <span class="small faint">revision <?= (int) $audit->contractRevision ?></span>
        <?php endif; ?>
    </div>
    <dl class="kv">
        <dt>Goal</dt><dd><?= TemplateRenderer::escape($audit->contractGoal ?? '— none planned —') ?></dd>
        <dt>Approved by</dt><dd><?= TemplateRenderer::escape($audit->approvalBy ?? 'not approved') ?></dd>
        <dt>Approved at</dt><dd><?= TemplateRenderer::escape($audit->approvalAt ?? 'not approved') ?></dd>
    </dl>
</section>

<p class="eyebrow">Session, verification and recall</p>
<div class="grid">
    <section class="panel">
        <h2>Session</h2>
        <dl class="kv">
            <dt>Status</dt><dd><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($audit->sessionStatus)) ?>"><?= TemplateRenderer::escape(Presentation::label($audit->sessionStatus)) ?></span></dd>
            <dt>Active</dt><dd><?= (int) $audit->activeSessionCount ?> of <?= (int) $audit->sessionCount ?></dd>
        </dl>
    </section>
    <section class="panel">
        <h2>Verification</h2>
        <dl class="kv">
            <dt>Status</dt><dd><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($audit->verificationStatus)) ?>"><?= TemplateRenderer::escape(Presentation::label($audit->verificationStatus)) ?></span></dd>
            <dt>Receipt</dt><dd><code><?= TemplateRenderer::escape($audit->verificationPath) ?></code></dd>
        </dl>
    </section>
    <section class="panel">
        <h2>Recall</h2>
        <dl class="kv">
            <dt>Status</dt><dd><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($audit->recallStatus)) ?>"><?= TemplateRenderer::escape(Presentation::label($audit->recallStatus)) ?></span></dd>
            <dt>Outcome draft</dt><dd><?= $audit->recallOutcomeDraft ? 'present' : 'none' ?></dd>
            <dt>Logged outcomes</dt><dd><?= (int) $audit->recallLoggedOutcomes ?></dd>
        </dl>
        <?php if ($audit->recallTaskFiles !== []): ?>
            <p class="note" style="margin-bottom:4px">Task files</p>
            <dl class="kv"><?php foreach ($audit->recallTaskFiles as $file): ?>
                <dt></dt><dd><code><?= TemplateRenderer::escape($file) ?></code></dd>
            <?php endforeach; ?></dl>
        <?php endif; ?>
    </section>
</div>

<p class="eyebrow">Declared validation</p>
<section class="panel">
    <?php if ($audit->validation === []): ?>
        <p class="empty">No Contract validation obligations are projected.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Command</th><th>Status</th><th>Exit</th><th>Executed</th><th>Source</th></tr></thead>
            <tbody>
            <?php foreach ($audit->validation as $validation): ?>
                <tr>
                    <td class="mono"><?= TemplateRenderer::escape($validation->command) ?></td>
                    <td><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($validation->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($validation->status)) ?></span></td>
                    <td class="num"><?= TemplateRenderer::escape($validation->exitCode === null ? '—' : (string) $validation->exitCode) ?></td>
                    <td class="small faint"><?= TemplateRenderer::escape($validation->executedAt ?? 'not executed') ?></td>
                    <td class="small faint">rev <?= (int) $validation->contractRevision ?> · <?= TemplateRenderer::escape($validation->source) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<p class="eyebrow">Review and Learning</p>
<div class="grid">
    <section class="panel<?= $audit->reviewInvalid ? ' panel--danger' : '' ?>">
        <h2>Human review</h2>
        <dl class="kv">
            <dt>Report</dt>
            <dd><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($audit->reviewExists ? ($audit->reviewStatus ?? 'present') : 'missing')) ?>"><?= TemplateRenderer::escape(Presentation::label($audit->reviewExists ? ($audit->reviewStatus ?? 'present') : 'missing')) ?></span></dd>
            <dt>Acknowledged by</dt><dd><?= TemplateRenderer::escape($audit->reviewAcknowledgedBy ?? 'not acknowledged') ?></dd>
            <dt>Acknowledged at</dt><dd><?= TemplateRenderer::escape($audit->reviewAcknowledgedAt ?? 'not acknowledged') ?></dd>
            <dt>Path</dt><dd><code><?= TemplateRenderer::escape($audit->reviewPath) ?></code></dd>
        </dl>
        <p class="note">A report's verdict and a human acknowledgement are separate facts. Neither is workflow authority.</p>
    </section>
    <section class="panel">
        <h2>Learning</h2>
        <dl class="kv">
            <dt>Status</dt><dd><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($audit->learningStatus)) ?>"><?= TemplateRenderer::escape(Presentation::label($audit->learningStatus)) ?></span></dd>
            <dt>Decision</dt><dd><?= TemplateRenderer::escape(Presentation::label($audit->learningDecision ?? 'not decided')) ?></dd>
            <dt>Decided by</dt><dd><?= TemplateRenderer::escape($audit->learningDecidedBy ?? '—') ?></dd>
            <dt>Decided at</dt><dd><?= TemplateRenderer::escape($audit->learningDecidedAt ?? '—') ?></dd>
            <dt>Findings</dt><dd><?= (int) $audit->learningFindings ?> · <?= (int) $audit->learningProposals ?> proposal(s) · <?= (int) $audit->learningOutcomes ?> outcome(s)</dd>
            <?php if ($audit->learningReason !== null): ?><dt>Reason</dt><dd><?= TemplateRenderer::escape($audit->learningReason) ?></dd><?php endif; ?>
            <?php if ($audit->learningFollowUpRef !== null): ?><dt>Follow-up</dt><dd><code><?= TemplateRenderer::escape($audit->learningFollowUpRef) ?></code></dd><?php endif; ?>
            <?php if ($audit->learningFindingIds !== []): ?><dt>Finding IDs</dt><dd class="mono small"><?= TemplateRenderer::escape(implode(', ', $audit->learningFindingIds)) ?></dd><?php endif; ?>
        </dl>
    </section>
</div>

<details class="raw">
    <summary>Raw lifecycle references (<?= count($workflow->references) ?>)</summary>
    <div class="stack">
        <?php foreach ($workflow->references as $name => $reference): ?>
            <section class="panel">
                <h2><?= TemplateRenderer::escape(str_replace('_', ' ', (string) $name)) ?></h2>
                <div class="codeblock"><pre><?= TemplateRenderer::escape((string) json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?></pre></div>
            </section>
        <?php endforeach; ?>
    </div>
</details>

<div class="btn-row">
    <a class="btn" href="/task/<?= TemplateRenderer::escape($workflow->taskId) ?>/history">Audit history</a>
    <a class="btn" href="/task/<?= TemplateRenderer::escape($workflow->taskId) ?>">← Back to task</a>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
