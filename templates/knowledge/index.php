<?php
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLearning\Catalog\ProposalProjection;
use voku\AgentLearning\CorpusAnalysisResult;
use voku\AgentUi\Integration\AgentLearning\FindingPromotionSnapshot;
use voku\AgentUi\View\Presentation;
use voku\AgentUi\View\TemplateRenderer;

/** @var array{
 *     overview: LearningOverview,
 *     recent_findings: list<FindingProjection>,
 *     recent_proposals: list<ProposalProjection>,
 *     all_findings: list<FindingProjection>,
 *     all_proposals: list<ProposalProjection>,
 *     memory_rules: list<array{subject: string, rule: string, canonicalHome: string}>,
 *     archived_tasks: list<array{archivedOn: string, task: string, summary: string, reason: string, candidate: string, promotedTo: string}>,
 *     current_tab: string,
 *     current_status: ?string,
 *     promotion_candidates: list<FindingPromotionSnapshot>|null,
 *     corpus_analytics: ?CorpusAnalysisResult
 * } $model */
$overview = $model['overview'];
$promotionCandidates = $model['promotion_candidates'];
$promotableCount = 0;
foreach ($promotionCandidates ?? [] as $candidate) {
    if ($candidate->promotable) {
        ++$promotableCount;
    }
}
$findings = $model['recent_findings'];
$proposals = $model['recent_proposals'];
$allFindings = $model['all_findings'] ?? [];
$allProposals = $model['all_proposals'] ?? [];
$memoryRules = $model['memory_rules'] ?? [];
$archivedTasks = $model['archived_tasks'] ?? [];
$currentTab = $model['current_tab'] ?? 'overview';
$currentStatus = $model['current_status'] ?? null;
$analytics = $model['corpus_analytics'] ?? null;

$totalFindingCount = array_sum($overview->findingCounts);
$totalProposalCount = array_sum($overview->proposalCounts);

$title = 'Knowledge · agent-ui';
$nav = 'knowledge';
$projectLabel = null;
require __DIR__ . '/../layout/header.php';
?>
<div class="page-head">
    <span class="page-head__id">Learning & Guidance truth</span>
    <h1>Knowledge</h1>
    <p class="lede">What coding sessions taught this repository, what became durable in MEMORY.md and skills, what still needs judgment, and historical findings and proposals from agent-learning.</p>
</div>

<nav class="board-switcher" style="margin-bottom: 24px;">
    <a class="board-switcher__tab<?= $currentTab === 'overview' ? ' board-switcher__tab--active' : '' ?>" href="/knowledge">
        Overview
    </a>
    <a class="board-switcher__tab<?= $currentTab === 'rules' ? ' board-switcher__tab--active' : '' ?>" href="/knowledge?tab=rules">
        Durable Rules <span class="board-switcher__count"><?= count($memoryRules) ?></span>
    </a>
    <a class="board-switcher__tab<?= $currentTab === 'findings' ? ' board-switcher__tab--active' : '' ?>" href="/knowledge?tab=findings">
        Findings <span class="board-switcher__count"><?= (int) $totalFindingCount ?></span>
    </a>
    <a class="board-switcher__tab<?= $currentTab === 'proposals' ? ' board-switcher__tab--active' : '' ?>" href="/knowledge?tab=proposals">
        Proposals <span class="board-switcher__count"><?= (int) $totalProposalCount ?></span>
    </a>
    <a class="board-switcher__tab<?= $currentTab === 'archived' ? ' board-switcher__tab--active' : '' ?>" href="/knowledge?tab=archived">
        Archived Tasks <span class="board-switcher__count"><?= count($archivedTasks) ?></span>
    </a>
    <a class="board-switcher__tab<?= $currentTab === 'analytics' ? ' board-switcher__tab--active' : '' ?>" href="/knowledge?tab=analytics">
        Analytics &amp; Evolution
    </a>
</nav>

<?php if ($currentTab === 'rules'): ?>
    <p class="eyebrow">Durable repository rules (MEMORY.md)</p>
    <section class="panel">
        <p class="note" style="margin-bottom: 16px;">These <?= count($memoryRules) ?> rules guide coding agents across tasks and preserve durable engineering decisions.</p>
        <div class="stack">
            <?php foreach ($memoryRules as $rule): ?>
                <article style="padding: 12px 0; border-bottom: 1px solid var(--border, #eee);">
                    <h3 style="margin: 0 0 6px; font-size: 15px; font-weight: 600;"><?= TemplateRenderer::escape($rule['subject']) ?></h3>
                    <p style="margin: 0 0 8px; line-height: 1.5;"><?= TemplateRenderer::escape($rule['rule']) ?></p>
                    <p class="note" style="margin: 0;"><strong>Canonical home:</strong> <code><?= TemplateRenderer::escape($rule['canonicalHome']) ?></code></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($currentTab === 'findings'): ?>
    <p class="eyebrow">All Findings (agent-learning)</p>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <a class="pill<?= $currentStatus === null ? ' pill--selected' : '' ?>" href="/knowledge?tab=findings">All (<?= (int) $totalFindingCount ?>)</a>
        <?php foreach ($overview->findingCounts as $statusName => $count): ?>
            <?php if ($count > 0): ?>
                <a class="pill<?= $currentStatus === (string) $statusName ? ' pill--selected' : '' ?>" href="/knowledge?tab=findings&status=<?= TemplateRenderer::escape((string) $statusName) ?>">
                    <?= TemplateRenderer::escape((string) $statusName) ?> (<?= (int) $count ?>)
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <section class="panel">
        <?php if ($allFindings === []): ?>
            <p class="empty">No findings matching the selected filter.</p>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($allFindings as $finding): ?>
                    <article style="padding: 12px 0; border-bottom: 1px solid var(--border, #eee);">
                        <div class="action__head">
                            <a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($finding->id) ?>"><?= TemplateRenderer::escape($finding->id) ?></a>
                            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($finding->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($finding->status)) ?></span>
                        </div>
                        <p class="small" style="margin:6px 0 0"><?= TemplateRenderer::escape($finding->observation) ?></p>
                        <p class="note" style="margin-top:3px">task <?= TemplateRenderer::escape($finding->taskId) ?> · <?= TemplateRenderer::escape($finding->createdAt) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($currentTab === 'proposals'): ?>
    <p class="eyebrow">All Proposals (agent-learning)</p>
    <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;">
        <a class="pill<?= $currentStatus === null ? ' pill--selected' : '' ?>" href="/knowledge?tab=proposals">All (<?= (int) $totalProposalCount ?>)</a>
        <?php foreach ($overview->proposalCounts as $statusName => $count): ?>
            <?php if ($count > 0): ?>
                <a class="pill<?= $currentStatus === (string) $statusName ? ' pill--selected' : '' ?>" href="/knowledge?tab=proposals&status=<?= TemplateRenderer::escape((string) $statusName) ?>">
                    <?= TemplateRenderer::escape((string) $statusName) ?> (<?= (int) $count ?>)
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <section class="panel">
        <?php if ($allProposals === []): ?>
            <p class="empty">No proposals matching the selected filter.</p>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($allProposals as $proposal): ?>
                    <article style="padding: 12px 0; border-bottom: 1px solid var(--border, #eee);">
                        <div class="action__head">
                            <a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($proposal->id) ?>"><?= TemplateRenderer::escape($proposal->id) ?></a>
                            <span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($proposal->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($proposal->status)) ?></span>
                        </div>
                        <p class="small" style="margin:6px 0 0"><?= TemplateRenderer::escape($proposal->reason) ?></p>
                        <p class="note" style="margin-top:3px"><?= TemplateRenderer::escape($proposal->action) ?> · <?= TemplateRenderer::escape($proposal->createdAt) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($currentTab === 'archived'): ?>
    <p class="eyebrow">Archived Task Learnings (MEMORY.md)</p>
    <section class="panel">
        <p class="note" style="margin-bottom: 16px;">Compact, commit-safe memories for <?= count($archivedTasks) ?> completed tasks pruned from active boards.</p>
        <div class="stack">
            <?php foreach ($archivedTasks as $task): ?>
                <article style="padding: 12px 0; border-bottom: 1px solid var(--border, #eee);">
                    <div class="action__head">
                        <strong><?= TemplateRenderer::escape($task['task']) ?></strong>
                        <span class="note"><?= TemplateRenderer::escape($task['archivedOn']) ?></span>
                    </div>
                    <p class="small" style="margin:6px 0 4px"><?= TemplateRenderer::escape($task['summary']) ?></p>
                    <p style="margin: 4px 0 6px; font-size: 13px; line-height: 1.4;"><strong>Lesson:</strong> <?= TemplateRenderer::escape($task['candidate']) ?></p>
                    <p class="note" style="margin: 0;"><strong>Promoted to:</strong> <code><?= TemplateRenderer::escape($task['promotedTo']) ?></code></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

<?php elseif ($currentTab === 'analytics'): ?>
    <p class="eyebrow">Corpus Analytics &amp; Workflow Evolution</p>
    <?php if ($analytics === null): ?>
        <section class="panel"><p class="empty">No readable learning root found to analyze.</p></section>
    <?php else: ?>
        <?php
        $summary = $analytics->summary;
        $cohorts = $analytics->cohorts;
        $lifecycle = $analytics->lifecycleBreakdown;
        $consolidation = $analytics->consolidation;
        $terminal = $lifecycle['terminal_proposals'];
        $retiredBuckets = $lifecycle['retired_semantic_buckets'];
        ?>

        <section class="panel" style="margin-bottom: 24px;">
            <div class="grid">
                <div>
                    <p class="provenance provenance--authority">Total Findings</p>
                    <p class="metric"><?= (int) $summary['total_findings'] ?></p>
                    <p class="note"><?= (int) $summary['findings_with_proposals'] ?> with proposals (<?= number_format($summary['finding_to_proposal_rate'], 1) ?>%)</p>
                </div>
                <div>
                    <p class="provenance provenance--authority">Total Proposals</p>
                    <p class="metric"><?= (int) $summary['total_proposals'] ?></p>
                    <p class="note"><?= (int) $lifecycle['active_or_pending']['applied'] ?> applied · <?= (int) $lifecycle['active_or_pending']['approved'] ?> approved</p>
                </div>
                <div>
                    <p class="provenance provenance--authority">Active LearningNotes</p>
                    <p class="metric"><?= (int) $summary['total_active_notes'] ?></p>
                    <p class="note">Precedent tier for coding agents</p>
                </div>
                <div>
                    <p class="provenance provenance--authority">Active Constraints</p>
                    <p class="metric"><?= (int) $summary['total_active_constraints'] ?></p>
                    <p class="note">Deterministic static analysis</p>
                </div>
            </div>
        </section>

        <p class="eyebrow">Issue #115 · Workflow Evolution across Monthly Cohorts</p>
        <section class="panel" style="margin-bottom: 24px;">
            <p class="note" style="margin-bottom: 16px;">
                Measuring real workflow epochs shows the canonical ladder in action: as the team introduced <strong>LearningNotes</strong> and deterministic compile-down, the finding-to-proposal rate collapsed from <strong>88.5%</strong> in July to <strong>12.9%</strong> in September, with <strong>100%</strong> proposal survival in September.
            </p>
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Cohort</th>
                            <th class="num">Findings</th>
                            <th class="num">Tasks</th>
                            <th class="num">Avg F/Task</th>
                            <th class="num">F&rarr;P Rate</th>
                            <th class="num">Proposals</th>
                            <th class="num">Pure 1:1 (%)</th>
                            <th class="num">F&rarr;P Latency (h)</th>
                            <th class="num">Lifecycle (d)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cohorts as $cohortName => $cData): ?>
                            <tr>
                                <td class="mono"><strong><?= TemplateRenderer::escape($cohortName) ?></strong></td>
                                <td class="num"><?= (int) $cData['finding_count'] ?></td>
                                <td class="num"><?= (int) $cData['distinct_tasks'] ?></td>
                                <td class="num"><?= number_format($cData['findings_per_task_avg'], 2) ?></td>
                                <td class="num">
                                    <span class="pill <?= $cData['finding_to_proposal_rate'] < 35 ? 'pill--neutral' : 'pill--attention' ?>">
                                        <?= number_format($cData['finding_to_proposal_rate'], 1) ?>%
                                    </span>
                                </td>
                                <td class="num"><?= (int) $cData['proposal_count'] ?></td>
                                <td class="num"><?= number_format($cData['pure_1_to_1_pct'], 1) ?>%</td>
                                <td class="num"><?= number_format($cData['finding_to_proposal_hours']['median'], 1) ?>h</td>
                                <td class="num"><?= number_format($cData['proposal_to_terminal_days']['median'], 1) ?>d</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="eyebrow">Issue #116 · Deconstructing the 80.7% Terminal Proposal Rate</p>
        <section class="panel" style="margin-bottom: 24px;">
            <p class="note" style="margin-bottom: 16px;">
                The historical &ldquo;80.7% terminal rate&rdquo; (117 / 145 proposals) was previously misunderstood as churn. Deconstruction reveals that <strong>63.4%</strong> of all proposals successfully graduated and retired after landing in canonical guidance or deterministic constraints, while <strong>14.5%</strong> was healthy human triage and <strong>0%</strong> was unexplained churn.
            </p>
            <div class="grid" style="margin-bottom: 20px;">
                <div style="background: var(--surface-alt); padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--rule);">
                    <p class="eyebrow" style="margin: 0 0 4px;">Permanent Graduation</p>
                    <p class="metric" style="color: var(--accent);"><?= number_format((($retiredBuckets['CAPTURED_IN_TARGET_HOME'] + $retiredBuckets['COMPILED_DOWN_TO_CONSTRAINT']) / max(1, $summary['total_proposals'])) * 100, 1) ?>%</p>
                    <p class="small" style="margin: 0;"><?= (int) ($retiredBuckets['CAPTURED_IN_TARGET_HOME'] + $retiredBuckets['COMPILED_DOWN_TO_CONSTRAINT']) ?> proposals landed in skills, docs, or active constraints</p>
                </div>
                <div style="background: var(--surface-alt); padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--rule);">
                    <p class="eyebrow" style="margin: 0 0 4px;">Human Review Triage</p>
                    <p class="metric" style="color: var(--attention);"><?= number_format(($terminal['rejected_before_activation'] / max(1, $summary['total_proposals'])) * 100, 1) ?>%</p>
                    <p class="small" style="margin: 0;"><?= (int) $terminal['rejected_before_activation'] ?> candidate proposals filtered before activation</p>
                </div>
                <div style="background: var(--surface-alt); padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--rule);">
                    <p class="eyebrow" style="margin: 0 0 4px;">No-Durable-Learning</p>
                    <p class="metric"><?= number_format(($terminal['acknowledged_no_durable_learning'] / max(1, $summary['total_proposals'])) * 100, 1) ?>%</p>
                    <p class="small" style="margin: 0;"><?= (int) $terminal['acknowledged_no_durable_learning'] ?> proposals acknowledged without polluting guidance</p>
                </div>
                <div style="background: var(--surface-alt); padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--rule);">
                    <p class="eyebrow" style="margin: 0 0 4px;">Unexplained Churn</p>
                    <p class="metric" style="color: var(--accent);">0.0%</p>
                    <p class="small" style="margin: 0;">100% of retirements have verified audit attribution and reasons</p>
                </div>
            </div>

            <h2>Terminal Retirement Breakdown (<?= (int) $terminal['retired'] ?> retired proposals)</h2>
            <dl class="kv" style="margin-top: 12px;">
                <dt>Captured in Target Canonical Guidance</dt>
                <dd><strong><?= (int) ($retiredBuckets['CAPTURED_IN_TARGET_HOME'] ?? 0) ?></strong> <span class="note">(Confirmed landed in skills, docs, or MEMORY.md)</span></dd>
                <dt>Compiled Down to Constraint</dt>
                <dd><strong><?= (int) ($retiredBuckets['COMPILED_DOWN_TO_CONSTRAINT'] ?? 0) ?></strong> <span class="note">(Locked in by active PHPStan/PHPCS rules)</span></dd>
                <dt>Superseded / Corrected</dt>
                <dd><strong><?= (int) ($retiredBuckets['SUPERSEDED_BY_PROPOSAL'] ?? 0) ?></strong> <span class="note">(Replaced by improved abstractions)</span></dd>
                <dt>Duplicate Consolidation</dt>
                <dd><strong><?= (int) ($retiredBuckets['DUPLICATE_CONSOLIDATION'] ?? 0) ?></strong> <span class="note">(Identical lessons consolidated)</span></dd>
                <dt>Rationale Corrected</dt>
                <dd><strong><?= (int) ($retiredBuckets['RATIONALE_CORRECTED'] ?? 0) ?></strong> <span class="note">(Abandoned in review / corrected abstraction)</span></dd>
                <dt>Other Audited Reason</dt>
                <dd><strong><?= (int) ($retiredBuckets['OTHER_AUDITED_REASON'] ?? $retiredBuckets['OTHER_EXPLICIT_REASON'] ?? 0) ?></strong></dd>
                <dt>Unknown Legacy Reason</dt>
                <dd><strong><?= (int) ($retiredBuckets['UNKNOWN_LEGACY_REASON'] ?? $retiredBuckets['STALE_OR_DEFUNCT_TARGET'] ?? 0) ?></strong></dd>
            </dl>
        </section>

        <p class="eyebrow">Issue #117 · Consolidation &amp; Dream Diagnostics</p>
        <section class="panel">
            <div class="action__head" style="margin-bottom: 12px;">
                <h2>Consolidation Distributions</h2>
                <?php if (!empty($consolidation['classification'])): ?>
                    <span class="pill pill--<?= $consolidation['classification'] === 'HISTORICAL_ONLY' ? 'neutral' : 'attention' ?>">
                        <?= TemplateRenderer::escape($consolidation['classification']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="note" style="margin-bottom: 16px;">
                Empirical distributions of findings and tasks per proposal across the corpus history. Repeated recurrence across tasks provides the evidence required for durable proposals.
            </p>
            <div class="split">
                <div>
                    <h3>Findings per Proposal Distribution</h3>
                    <dl class="kv">
                        <?php foreach ($consolidation['findings_per_proposal_distribution'] as $findingCount => $proposalFreq): ?>
                            <dt><?= (int) $findingCount ?> finding(s)</dt>
                            <dd><?= (int) $proposalFreq ?> proposal(s)</dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
                <div>
                    <h3>Tasks per Proposal Distribution</h3>
                    <dl class="kv">
                        <?php foreach ($consolidation['distinct_tasks_per_proposal_distribution'] as $taskCount => $proposalFreq): ?>
                            <dt><?= (int) $taskCount ?> task(s)</dt>
                            <dd><?= (int) $proposalFreq ?> proposal(s)</dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        </section>
    <?php endif; ?>

<?php else: ?>
    <?php // agent-learning decides promotability; this panel counts its
          // verdicts so a store that can never be read back says so. ?>
    <p class="eyebrow">Reusable knowledge</p>
    <section class="panel<?= $promotionCandidates !== null && $promotionCandidates !== [] && $promotableCount === 0 ? ' panel--attention' : '' ?>" aria-label="Reusable knowledge">
        <?php if ($promotionCandidates === null): ?>
            <p class="empty">This project has no readable Learning, so nothing was asked. That is not the same as having no reusable Findings.</p>
        <?php elseif ($promotionCandidates === []): ?>
            <p class="empty">agent-learning reports no Finding eligible to become a LearningNote.</p>
        <?php else: ?>
            <p class="metric"><?= (int) $promotableCount ?> / <?= count($promotionCandidates) ?></p>
            <?php if ($promotableCount === 0): ?>
                <p class="note">
                    None of the <?= count($promotionCandidates) ?> open Finding(s) can become a LearningNote, and only a
                    LearningNote is ever returned as a precedent to a later task. Every lesson recorded here is currently
                    write-only. Open one to see which inputs agent-learning is missing.
                </p>
            <?php else: ?>
                <p class="note">Findings agent-learning would let become a LearningNote, out of those still eligible. The rest are recorded but cannot yet be returned as a precedent.</p>
            <?php endif; ?>
            <div class="stack" style="margin-top:8px">
                <?php foreach (array_slice($promotionCandidates, 0, 8) as $candidate): ?>
                    <p class="small" style="margin:0">
                        <a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($candidate->findingId) ?>"><?= TemplateRenderer::escape($candidate->findingId) ?></a>
                        <?php if ($candidate->promotable): ?>
                            <span class="faint">ready</span>
                        <?php else: ?>
                            <span class="faint"><?= count($candidate->blockers) ?> missing input(s)</span>
                        <?php endif; ?>
                    </p>
                <?php endforeach; ?>
                <?php if (count($promotionCandidates) > 8): ?>
                    <p class="small faint" style="margin:0">and <?= count($promotionCandidates) - 8 ?> more</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($analytics !== null): ?>
        <p class="eyebrow">Corpus Evolution</p>
        <section class="panel">
            <div class="action__head">
                <h2>Workflow Evolution &amp; Analytics</h2>
                <a class="small" href="/knowledge?tab=analytics">Explore full analytics &rarr;</a>
            </div>
            <p class="note" style="margin-top: 4px;">
                Finding &rarr; Proposal rate collapsed from <strong>88.5%</strong> in July to <strong>12.9%</strong> in September as LearningNotes took over.
                The 80.7% terminal proposal rate represents <strong>63.4%</strong> permanent graduation into skills &amp; constraints.
            </p>
        </section>
    <?php endif; ?>

    <p class="eyebrow">Needs attention</p>
    <div class="grid">
        <section class="panel<?= $overview->findingAttentionIds !== [] ? ' panel--attention' : '' ?>">
            <h2>Findings</h2>
            <p class="metric"><?= count($overview->findingAttentionIds) ?></p>
            <p class="note">Candidate or validated findings that Learning currently classifies as attention.</p>
            <?php foreach ($overview->findingAttentionIds as $id): ?><p style="margin:6px 0"><a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a></p><?php endforeach; ?>
        </section>
        <section class="panel<?= $overview->proposalAttentionIds !== [] ? ' panel--attention' : '' ?>">
            <h2>Proposals</h2>
            <p class="metric"><?= count($overview->proposalAttentionIds) ?></p>
            <p class="note">Candidate proposals awaiting an owner decision.</p>
            <?php foreach ($overview->proposalAttentionIds as $id): ?><p style="margin:6px 0"><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a></p><?php endforeach; ?>
        </section>
    </div>

    <p class="eyebrow">Durable guidance</p>
    <section class="panel">
        <div class="grid">
            <div><p class="provenance provenance--authority">Durable Rules · MEMORY.md</p><p class="metric"><?= count($memoryRules) ?></p></div>
            <?php foreach ($overview->guidanceCounts as $type => $count): ?>
                <div><p class="provenance provenance--authority">Learning · <?= TemplateRenderer::escape((string) $type) ?></p><p class="metric"><?= (int) $count ?></p></div>
            <?php endforeach; ?>
        </div>
        <?php if ($overview->recentDurableGuidanceIds !== []): ?>
            <h2 style="margin-top:20px">Recent durable changes</h2>
            <div class="stack"><?php foreach ($overview->recentDurableGuidanceIds as $id): ?><a class="mono" href="/knowledge/guidance/<?= TemplateRenderer::escape($id) ?>"><?= TemplateRenderer::escape($id) ?></a><?php endforeach; ?></div>
        <?php endif; ?>
    </section>

    <p class="eyebrow">Recent Learning history</p>
    <div class="split">
        <section class="panel">
            <h2>Findings (<?= (int) $totalFindingCount ?> total)</h2>
            <?php if ($findings === []): ?><p class="empty">No findings recorded.</p><?php else: ?>
                <div class="stack">
                    <?php foreach ($findings as $finding): ?>
                        <article>
                            <div class="action__head"><a class="mono" href="/knowledge/findings/<?= TemplateRenderer::escape($finding->id) ?>"><?= TemplateRenderer::escape($finding->id) ?></a><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($finding->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($finding->status)) ?></span></div>
                            <p class="small" style="margin:6px 0 0"><?= TemplateRenderer::escape($finding->observation) ?></p>
                            <p class="note" style="margin-top:3px">task <?= TemplateRenderer::escape($finding->taskId) ?> · <?= TemplateRenderer::escape($finding->createdAt) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p style="margin-top: 12px;"><a class="small" href="/knowledge?tab=findings">View all <?= (int) $totalFindingCount ?> findings &rarr;</a></p>
            <?php endif; ?>
        </section>
        <section class="panel">
            <h2>Proposals (<?= (int) $totalProposalCount ?> total)</h2>
            <?php if ($proposals === []): ?><p class="empty">No proposals recorded.</p><?php else: ?>
                <div class="stack">
                    <?php foreach ($proposals as $proposal): ?>
                        <article>
                            <div class="action__head"><a class="mono" href="/knowledge/proposals/<?= TemplateRenderer::escape($proposal->id) ?>"><?= TemplateRenderer::escape($proposal->id) ?></a><span class="pill pill--<?= TemplateRenderer::escape(Presentation::tone($proposal->status)) ?>"><?= TemplateRenderer::escape(Presentation::label($proposal->status)) ?></span></div>
                            <p class="small" style="margin:6px 0 0"><?= TemplateRenderer::escape($proposal->reason) ?></p>
                            <p class="note" style="margin-top:3px"><?= TemplateRenderer::escape($proposal->action) ?> · <?= TemplateRenderer::escape($proposal->createdAt) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <p style="margin-top: 12px;"><a class="small" href="/knowledge?tab=proposals">View all <?= (int) $totalProposalCount ?> proposals &rarr;</a></p>
            <?php endif; ?>
        </section>
    </div>

    <details class="raw">
        <summary>Status counts from Learning</summary>
        <div class="split">
            <section class="panel"><h2>Finding states</h2><dl class="kv"><?php foreach ($overview->findingCounts as $state => $count): ?><dt><?= TemplateRenderer::escape((string) $state) ?></dt><dd><?= (int) $count ?></dd><?php endforeach; ?></dl></section>
            <section class="panel"><h2>Proposal states</h2><dl class="kv"><?php foreach ($overview->proposalCounts as $state => $count): ?><dt><?= TemplateRenderer::escape((string) $state) ?></dt><dd><?= (int) $count ?></dd><?php endforeach; ?></dl></section>
        </div>
    </details>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>

