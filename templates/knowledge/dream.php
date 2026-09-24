<?php
use voku\AgentLoop\Workflow\WorkflowDreamReport;
use voku\AgentUi\View\TemplateRenderer;

/*
 * Dream, as agent-learning evaluates it and agent-loop exposes it.
 *
 * Every decision type, warning code and metric below is printed as the owner
 * reports it. The page adds no ranking and no verdict: a decision is something
 * a reviewer may act on, and writing candidates only records them for that
 * review.
 */

/** @var array{report: ?WorkflowDreamReport, problem: ?string, csrf: string} $model */
$report = $model['report'];
$problem = $model['problem'];
$title = 'Dream · Knowledge · agent-ui';
$nav = 'knowledge';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/knowledge">Knowledge</a><span>/</span>Dream</p>
<div class="page-head">
    <h1>Dream</h1>
    <p class="lede">agent-learning re-reads the findings, proposals and guidance outcomes in this repository and lists what a reviewer could promote, replace, retire or reconcile. Viewing this page writes nothing.</p>
</div>

<?php if ($problem !== null || !$report instanceof WorkflowDreamReport): ?>
<section class="panel panel--attention" aria-labelledby="dream-problem">
    <h2 id="dream-problem">Dream could not run</h2>
    <p><?= TemplateRenderer::escape($problem ?? 'agent-loop returned no Dream report.') ?></p>
    <p class="note">This is agent-learning's own validation of the Learning root. Nothing was written.</p>
</section>
<?php else: ?>
<?php
$result = $report->outcome->result;
$metrics = $result->metrics;
$decisions = $report->reviewableDecisions();
?>
<p class="eyebrow">Run</p>
<section class="panel">
    <dl class="facts">
        <div><dt>Learning root</dt><dd class="mono"><?= TemplateRenderer::escape($report->learningRoot) ?></dd></div>
        <div><dt>History input</dt><dd class="mono">sha256:<?= TemplateRenderer::escape($report->outcome->projection->inputDigest) ?></dd></div>
        <div><dt>Evaluated guidance</dt><dd><?= $result->evaluatedGuidanceCount ?></dd></div>
        <div><dt>Reviewable decisions</dt><dd><?= $metrics->reviewableDecisionCount ?></dd></div>
        <div><dt>Suppressed decisions</dt><dd><?= $metrics->suppressedDecisionCount ?> <span class="note">(already written as candidates)</span></dd></div>
        <div><dt>Candidate queue</dt><dd><?= $metrics->candidateQueueCount ?><?php if ($metrics->oldestCandidateAgeDays !== null): ?> · oldest <?= $metrics->oldestCandidateAgeDays ?> day(s)<?php endif; ?></dd></div>
        <div><dt>Outcome coverage</dt><dd><?= $metrics->explicitOutcomeCount ?> / <?= $metrics->selectedGuidanceCount ?> selected guidance judged</dd></div>
    </dl>
    <p class="note"><?= TemplateRenderer::escape($result->remainingUncertainty) ?></p>
</section>

<?php if ($result->warnings !== []): ?>
<p class="eyebrow">Warnings</p>
<section class="panel panel--attention">
    <ul class="stack" style="list-style:none;margin:0;padding:0">
        <?php foreach ($result->warnings as $warning): ?>
            <li>
                <span class="pill pill--attention"><?= TemplateRenderer::escape($warning->code) ?></span>
                <?= TemplateRenderer::escape($warning->message) ?>
                <?php if ($warning->remediation !== ''): ?><p class="note" style="margin:4px 0 0"><?= TemplateRenderer::escape($warning->remediation) ?></p><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<p class="eyebrow">Reviewable decisions</p>
<?php if ($decisions === []): ?>
<section class="panel"><p class="empty">Dream found nothing new to review.</p></section>
<?php else: ?>
<section class="panel">
    <div class="table-scroll">
        <table class="table">
            <thead><tr><th>Decision</th><th>Subject</th><th>Tier</th><th>Why</th><th class="num">Tasks</th></tr></thead>
            <tbody>
            <?php foreach ($decisions as $decision): ?>
                <tr>
                    <td><span class="pill"><?= TemplateRenderer::escape($decision->type->value) ?></span></td>
                    <td class="mono"><?= TemplateRenderer::escape($decision->guidanceId) ?></td>
                    <td><?= TemplateRenderer::escape($decision->sourceTier->value) ?><?php if ($decision->targetTier !== null && $decision->targetTier !== $decision->sourceTier): ?> → <?= TemplateRenderer::escape($decision->targetTier->value) ?><?php endif; ?></td>
                    <td>
                        <?= TemplateRenderer::escape($decision->reason) ?>
                        <?php if ($decision->newText !== null): ?><p class="note" style="margin:4px 0 0"><?= TemplateRenderer::escape($decision->newText) ?></p><?php endif; ?>
                        <?php if ($decision->sourceFindings !== []): ?>
                            <p class="small" style="margin:4px 0 0"><?php foreach ($decision->sourceFindings as $index => $findingId): ?><?= $index > 0 ? ', ' : '' ?><a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($findingId) ?>"><?= TemplateRenderer::escape($findingId) ?></a><?php endforeach; ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= count($decision->independentTaskIds) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<p class="eyebrow">Record for review</p>
<section class="panel">
    <form class="form" method="post" action="/knowledge/dream">
        <input type="hidden" name="_csrf" value="<?= TemplateRenderer::escape($model['csrf']) ?>">
        <p style="margin-top:0">Writing records the writable decisions above as <strong>candidate</strong> Proposals in agent-learning. Nothing becomes guidance until a reviewer approves it, and a decision already written is suppressed on the next run. Dream is re-run when you submit, so the candidates reflect the repository at that moment, not this page.</p>
        <label class="confirm">
            <input type="checkbox" name="confirm_write" value="write" required>
            <span>Write candidate Proposals for review.</span>
        </label>
        <button class="btn btn--primary" type="submit">Write candidates</button>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
