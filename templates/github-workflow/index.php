<?php
use voku\AgentUi\View\ClientScript;

$title = 'Cross-repository work · agent-ui';
$nav = 'github_workflow';
$projectLabel = 'GitHub observation · read-only';
require __DIR__ . '/../layout/header.php';
?>
<p class="crumbs"><a href="/">Overview</a><span>/</span>Cross-repository work</p>
<div class="page-head">
    <h1>Cross-repository work</h1>
    <p class="lede">Follow GitHub evidence across PRs, issues, releases and consumers. GitHub observations are not Loop approval or execution authority. Missing proof stays missing.</p>
</div>
<noscript><p class="note">This optional cross-repository view requires JavaScript for GitHub refresh and JSON import/export. The ordinary agent-loop owner views remain available without JavaScript.</p></noscript>
<section class="panel" id="github-workflow" aria-label="GitHub workbench" hidden>
    <p class="note" id="gh-message" role="status">The public GitHub API is not loaded yet. Use Refresh or import a verified evidence snapshot.</p>
    <div class="action__head">
        <div>
            <button type="button" class="btn" id="gh-refresh">Refresh GitHub</button>
            <button type="button" class="btn" id="gh-export">Export JSON</button>
            <label class="btn" for="gh-import">Import JSON</label>
            <input type="file" id="gh-import" accept="application/json,.json" hidden>
        </div>
        <span class="small faint" id="gh-updated">No GitHub observation yet</span>
    </div>
    <p class="note">Automatic refresh: every 30 minutes while this tab is visible. Public GitHub API rate limits apply. Only metadata is refreshed in bulk; detailed checks and reviews load on selection. No GitHub token is stored in the browser.</p>
    <div class="grid" style="margin-top:16px">
        <label>Repository
            <select id="gh-repo"><option value="">All repositories</option></select>
        </label>
        <label>Type
            <select id="gh-kind"><option value="">PR + Issue</option><option value="pr">PR</option><option value="issue">Issue</option></select>
        </label>
        <label>Phase
            <select id="gh-phase"><option value="">All phases</option><option>fix</option><option>release</option><option>consumer</option><option>verify</option><option>research</option></select>
        </label>
        <label>Status
            <select id="gh-status"><option value="">All statuses</option><option value="blocked">Blocked</option><option value="review">Review needed</option><option value="observed">Observed</option><option value="draft">Draft</option><option value="closed">Closed / merged</option></select>
        </label>
        <label>Evidence
            <select id="gh-proof"><option value="">Any evidence</option><option value="ci">Exact-head CI</option><option value="dogfood">Dogfood</option><option value="artifact">Artifact</option><option value="review">Review</option><option value="missing">Missing / unverified</option></select>
        </label>
        <label>Freshness
            <select id="gh-freshness"><option value="">Any</option><option value="current">Current-head</option><option value="stale">Stale</option><option value="unknown">Unknown</option></select>
        </label>
        <label>Blocker
            <select id="gh-blocker"><option value="">Any</option><option value="yes">Blocked</option><option value="no">Not identified</option></select>
        </label>
        <label>Find
            <input id="gh-query" type="search" placeholder="PR, issue, claim, repository">
        </label>
    </div>
    <p class="small muted" id="gh-count"></p>
    <div class="table-scroll">
        <table class="table"><thead><tr><th>Compare</th><th>Priority / work item</th><th>Phase</th><th>Current head</th><th>Proof</th><th>Next investigation</th></tr></thead>
            <tbody id="gh-list"><tr><td colspan="6">Refresh or import a snapshot to begin.</td></tr></tbody>
        </table>
    </div>
    <div class="action__head" style="margin-top:18px">
        <h2>Selected work item</h2>
        <button type="button" class="btn" id="gh-compare">Compare selected</button>
    </div>
    <div id="gh-detail"><p class="empty">Select a PR or issue to inspect its evidence.</p></div>
    <div id="gh-comparison" hidden></div>
</section>
<p class="note">Coding hosts: Codex, Claude Code, OpenCode or another host-native CLI continue through <code>agent-loop enter</code> and <code>finish</code> in their own environment. This page never silently approves a Contract or merges a pull request. Runner remains optional.</p>
<script><?= ClientScript::githubWorkflowCode() ?></script>
<?php require __DIR__ . '/../layout/footer.php'; ?>
