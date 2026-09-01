<?php
use voku\AgentLearning\Catalog\FindingProjection;
use voku\AgentLearning\Catalog\LearningOverview;
use voku\AgentLearning\Catalog\ProposalProjection;
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
 *     current_status: ?string
 * } $model */
$overview = $model['overview'];
$findings = $model['recent_findings'];
$proposals = $model['recent_proposals'];
$allFindings = $model['all_findings'] ?? [];
$allProposals = $model['all_proposals'] ?? [];
$memoryRules = $model['memory_rules'] ?? [];
$archivedTasks = $model['archived_tasks'] ?? [];
$currentTab = $model['current_tab'] ?? 'overview';
$currentStatus = $model['current_status'] ?? null;

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

<?php else: ?>
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

