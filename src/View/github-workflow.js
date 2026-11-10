/* Cross-repository GitHub observation for agent-ui. No workflow authority. */
(function () {
    'use strict';

    var root = document.getElementById('github-workflow');
    if (!root || typeof window.fetch !== 'function') { return; }
    root.hidden = false;

    var repositories = [
        'voku/agent-loop', 'voku/agent-ui', 'voku/php-runtime-audit',
        'voku/phpstan-agent-format', 'voku/agent-learning',
        'voku/agent-recall-compiler'
    ];
    var state = { items: new Map(), checked: new Set(), selected: null, updatedAt: null, refreshing: false };
    var controls = ['repo', 'kind', 'phase', 'status', 'proof', 'freshness', 'blocker', 'query'];
    var message = document.getElementById('gh-message');
    var list = document.getElementById('gh-list');
    var detail = document.getElementById('gh-detail');
    var comparison = document.getElementById('gh-comparison');
    var nextRefresh = 0;
    var detailSequence = 0;
    var detailPending = new Map();
    var phaseWords = { release: /\brelease\b/i, consumer: /\bconsumer\b|\bdeps\b/i, verify: /\btest\b|\bverify\b|\bproof\b|\bci\b/i, research: /\bresearch\b|\bexperiment\b|\bdogfood\b/i };

    function element(tag, text, className) {
        var node = document.createElement(tag);
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        if (className) { node.className = className; }
        return node;
    }

    function append(parent, tag, text, className) {
        var child = element(tag, text, className);
        parent.appendChild(child);
        return child;
    }

    function link(parent, label, url) {
        if (typeof url !== 'string' || !/^https:\/\/github\.com\/voku\/[A-Za-z0-9._/-]+(?:[?#][A-Za-z0-9._~!$&'()*+,;=:@%/?#-]*)?$/.test(url)) {
            append(parent, 'span', label);
            return;
        }
        var a = append(parent, 'a', label);
        a.href = url;
        a.rel = 'noopener noreferrer';
        a.target = '_blank';
    }

    function itemUrl(item) {
        return 'https://github.com/' + item.repo + '/' + (item.kind === 'pr' ? 'pull' : 'issues') + '/' + item.number;
    }

    function shortSha(sha) {
        return typeof sha === 'string' && /^[a-f0-9]{40}$/.test(sha) ? sha.slice(0, 12) : 'unknown';
    }

    function phase(item) {
        if (['fix', 'release', 'consumer', 'verify', 'research'].includes(item.phase)) { return item.phase; }
        for (var key of Object.keys(phaseWords)) {
            if (phaseWords[key].test(item.title)) { return key; }
        }
        return 'fix';
    }

    function proofs(item) {
        return Array.isArray(item.proofs) ? item.proofs : [];
    }

    function fresh(item, proof) {
        return item.kind === 'pr' && /^[a-f0-9]{40}$/.test(item.head || '') &&
            proof.sha === item.head;
    }

    function freshness(item) {
        var current = proofs(item).some(function (p) { return fresh(item, p); });
        var stale = proofs(item).some(function (p) { return p.sha && !fresh(item, p); });
        if (current && stale) { return 'mixed'; }
        if (current) { return 'current'; }
        if (stale) { return 'stale'; }
        return 'unknown';
    }

    function hasEvidence(item, type) {
        if (type === 'missing') { return proofs(item).length === 0 || freshness(item) !== 'current'; }
        if (type === 'review') { return Array.isArray(item.reviews) && item.reviews.length > 0; }
        if (type === 'artifact') { return Array.isArray(item.artifacts) && item.artifacts.length > 0; }
        var matching = proofs(item).filter(function (p) { return p.type === type && fresh(item, p); });
        if (!['ci', 'dogfood'].includes(type)) { return matching.length > 0; }
        // Fail closed: skipped, neutral and pending checks do not establish passing proof.
        return matching.length > 0 && matching.every(function (p) { return p.outcome === 'success'; });
    }

    function failed(item) {
        var failures = ['failure', 'timed_out', 'cancelled', 'startup_failure', 'action_required'];
        return proofs(item).some(function (p) {
            return ['ci', 'dogfood'].includes(p.type) && fresh(item, p) && failures.includes(p.outcome);
        });
    }

    function latestReviews(item) {
        var byReviewer = new Map();
        (item.reviews || []).forEach(function (review) {
            if (review.state !== 'COMMENTED') { byReviewer.set(review.author, review); }
        });
        return Array.from(byReviewer.values());
    }

    function approvedAtHead(item) {
        return latestReviews(item).some(function (review) {
            return review.state === 'APPROVED' && review.sha === item.head;
        });
    }

    function changesRequestedAtHead(item) {
        return latestReviews(item).some(function (review) {
            return review.state === 'CHANGES_REQUESTED' && review.sha === item.head;
        });
    }

    function unresolvedDependency(item) {
        for (var id of item.dependsOn) {
            var dependency = state.items.get(id);
            if (!dependency || (dependency.kind === 'pr' && dependency.state !== 'merged') ||
                (dependency.kind === 'issue' && dependency.state !== 'closed')) {
                return id;
            }
        }
        return null;
    }

    function isBlocked(item) {
        return unresolvedDependency(item) !== null ||
            (Array.isArray(item.blockers) && item.blockers.length > 0) ||
            (Array.isArray(item.threads) && item.threads.some(function (t) { return t.resolved === false; })) ||
            failed(item) || changesRequestedAtHead(item);
    }

    function status(item) {
        if (item.state === 'closed' || item.state === 'merged') { return 'closed'; }
        if (isBlocked(item)) { return 'blocked'; }
        if (item.draft) { return 'draft'; }
        if (item.kind === 'pr' && !approvedAtHead(item)) {
            return 'review';
        }
        return 'observed';
    }

    function nextStep(item) {
        if (item.kind === 'issue') { return 'Inspect owner acceptance criteria; select a bounded next task.'; }
        if (item.state === 'merged') { return 'Verify release and installed consumer independently.'; }
        var dependency = unresolvedDependency(item);
        if (dependency) { return 'Inspect upstream ' + dependency + ' first, then recheck this exact head and consumer proof.'; }
        if (failed(item)) { return 'Inspect the exact-head failed job before any merge.'; }
        if (changesRequestedAtHead(item)) { return 'Address the latest changes-requested review and verify the current head.'; }
        if (Array.isArray(item.threads) && item.threads.some(function (t) { return t.resolved === false; })) {
            return 'Address the unresolved review thread and revalidate the head.';
        }
        if (Array.isArray(item.blockers) && item.blockers.length) { return 'Resolve: ' + item.blockers[0]; }
        if (freshness(item) === 'stale' || freshness(item) === 'mixed') {
            return 'Reconcile historical proof with the exact current head before advancing.';
        }
        if (!hasEvidence(item, 'ci')) { return 'Collect current-head CI jobs and their outcomes.'; }
        if (item.draft) { return 'Complete the experiment; retain human review gate.'; }
        if (!hasEvidence(item, 'dogfood')) { return 'Check meaningful consumer/host dogfood and review requirements.'; }
        return 'Check independent review, merge policy and human authority.';
    }

    function priority(item) {
        if (isBlocked(item)) { return 0; }
        if (freshness(item) === 'stale') { return 1; }
        if (status(item) === 'review') { return 2; }
        if (item.draft) { return 4; }
        return 3;
    }

    function normalize(raw) {
        if (!raw || !repositories.includes(raw.repo) || !Number.isSafeInteger(raw.number) ||
            raw.number <= 0 || !['pr', 'issue'].includes(raw.kind) || typeof raw.title !== 'string') {
            throw new Error('Invalid repository, number, type or title in snapshot.');
        }
        var id = raw.repo + '#' + raw.number;
        return {
            id: id, repo: raw.repo, number: raw.number, kind: raw.kind,
            title: raw.title.slice(0, 350), state: String(raw.state || 'open'),
            head: /^[a-f0-9]{40}$/.test(raw.head || '') ? raw.head : null,
            draft: raw.draft === true, phase: raw.phase || null,
            claim: typeof raw.claim === 'string' ? raw.claim.slice(0, 4000) : null,
            proofs: Array.isArray(raw.proofs) ? raw.proofs.slice(0, 100).map(function (p) {
                return { type: String(p.type || 'unknown').slice(0, 40),
                    sha: typeof p.sha === 'string' ? p.sha : null,
                    outcome: String(p.outcome || 'unknown').slice(0, 50),
                    label: String(p.label || 'Unnamed evidence').slice(0, 300),
                    url: typeof p.url === 'string' ? p.url : null,
                    observedAt: typeof p.observedAt === 'string' ? p.observedAt : null };
            }) : [],
            reviews: Array.isArray(raw.reviews) ? raw.reviews.slice(0, 100).map(function (r) {
                return { state: String(r.state || 'UNKNOWN').slice(0, 35),
                    author: String(r.author || 'unknown').slice(0, 100),
                    sha: typeof r.sha === 'string' ? r.sha : null };
            }) : [],
            threads: Array.isArray(raw.threads) ? raw.threads.slice(0, 100).map(function (t) {
                return { resolved: typeof t.resolved === 'boolean' ? t.resolved : null,
                    outdated: typeof t.outdated === 'boolean' ? t.outdated : null,
                    text: String(t.text || '').slice(0, 1400),
                    path: String(t.path || '').slice(0, 280) };
            }) : null,
            artifacts: Array.isArray(raw.artifacts) ? raw.artifacts.slice(0, 100).map(function (a) {
                return { name: String(a.name || 'Artifact').slice(0, 250),
                    url: typeof a.url === 'string' ? a.url : null };
            }) : null,
            blockers: Array.isArray(raw.blockers) ? raw.blockers.slice(0, 25).map(function (x) {
                return String(x).slice(0, 400);
            }) : [],
            dependsOn: Array.isArray(raw.dependsOn) ? raw.dependsOn.slice(0, 20).filter(function (x) {
                return typeof x === 'string' && /^voku\/[a-z-]+#[0-9]+$/.test(x);
            }) : [],
            relatedTo: Array.isArray(raw.relatedTo) ? raw.relatedTo.slice(0, 20).filter(function (x) {
                return typeof x === 'string' && /^voku\/[a-z-]+#[0-9]+$/.test(x);
            }) : [],
            observedAt: typeof raw.observedAt === 'string' ? raw.observedAt : null,
            detailsLoaded: raw.detailsLoaded === true,
            partial: raw.partial === true
        };
    }

    function currentFilters() {
        var f = {};
        controls.forEach(function (key) { f[key] = document.getElementById('gh-' + key).value.trim().toLowerCase(); });
        return f;
    }

    function visible() {
        var f = currentFilters();
        return Array.from(state.items.values()).filter(function (item) {
            return (!f.repo || item.repo === f.repo) && (!f.kind || item.kind === f.kind) &&
                (!f.phase || phase(item) === f.phase) && (!f.status || status(item) === f.status) &&
                (!f.proof || hasEvidence(item, f.proof)) &&
                (!f.freshness || freshness(item) === f.freshness) &&
                (!f.blocker || (f.blocker === 'yes') === isBlocked(item)) &&
                (!f.query || (item.repo + ' #' + item.number + ' ' + item.title + ' ' + (item.claim || '')).toLowerCase().includes(f.query));
        }).sort(function (a, b) { return priority(a) - priority(b) || a.repo.localeCompare(b.repo) || a.number - b.number; });
    }

    function renderList() {
        list.replaceChildren();
        var rows = visible();
        document.getElementById('gh-count').textContent = rows.length + ' of ' + state.items.size + ' work items shown. Priority is categorical, not a guessed score.';
        rows.forEach(function (item) {
            var tr = append(list, 'tr');
            var cell = append(tr, 'td');
            var check = append(cell, 'input');
            check.type = 'checkbox';
            check.checked = state.checked.has(item.id);
            check.setAttribute('aria-label', 'Compare ' + item.id);
            check.addEventListener('change', function () {
                if (check.checked) { state.checked.add(item.id); } else { state.checked.delete(item.id); }
            });
            cell = append(tr, 'td');
            var btn = append(cell, 'button', 'P' + priority(item) + ' · ' + item.repo.replace('voku/', '') + '#' + item.number, 'btn');
            btn.type = 'button';
            btn.addEventListener('click', function () { select(item.id); });
            append(cell, 'div', item.title, 'small');
            append(tr, 'td', phase(item) + (item.phase ? '' : ' (inferred)'));
            append(tr, 'td', shortSha(item.head), 'mono small');
            append(tr, 'td', freshness(item) + ' · ' + status(item));
            append(tr, 'td', nextStep(item), 'small');
        });
        if (!rows.length) { append(append(list, 'tr'), 'td', 'No matching observations. An empty result is not evidence of completion.').colSpan = 6; }
    }

    function section(parent, heading) {
        var s = append(parent, 'section', null, 'panel');
        s.style.marginTop = '12px';
        append(s, 'h3', heading);
        return s;
    }

    function notes(parent, records, formatter) {
        if (!records || !records.length) { append(parent, 'p', 'Not verified / not available.', 'muted'); return; }
        var ul = append(parent, 'ul');
        records.forEach(function (r) {
            var li = append(ul, 'li');
            formatter(li, r);
        });
    }

    function renderDetail() {
        detail.replaceChildren();
        var item = state.items.get(state.selected);
        if (!item) { append(detail, 'p', 'Select a work item.'); return; }
        var head = section(detail, item.repo + '#' + item.number + ' · ' + item.title);
        link(head, 'Open on GitHub', itemUrl(item));
        append(head, 'p', 'Type: ' + item.kind + ' · phase: ' + phase(item) +
            ' · state: ' + status(item) + ' · observed head: ' + (item.head || 'not applicable / unknown'));
        append(head, 'p', 'Observed at: ' + (item.observedAt || 'unknown') +
            '. GitHub metadata is not a workflow authorization.', 'small muted');

        var claim = section(detail, 'Claim (not proof)');
        append(claim, 'p', item.claim || 'No bounded claim imported or fetched.');

        var proof = section(detail, 'Proof · tied to current head');
        notes(proof, proofs(item), function (li, p) {
            append(li, 'span', '[' + p.type + '] ' + p.label + ' · ' + p.outcome +
                ' · ' + (fresh(item, p) ? 'CURRENT HEAD' : 'STALE / UNKNOWN') + ' · ' + shortSha(p.sha) + ' ');
            if (p.url) { link(li, 'source', p.url); }
        });

        var reviews = section(detail, 'Reviews and threads');
        notes(reviews, item.reviews, function (li, r) {
            append(li, 'span', r.state + ' by ' + r.author + ' · ' + shortSha(r.sha) +
                (item.kind === 'pr' && r.sha === item.head ? ' · CURRENT HEAD' : ' · STALE / UNKNOWN'));
        });
        if (item.threads === null) { append(reviews, 'p', 'Resolved/unresolved thread state UNKNOWN: unauthenticated REST does not expose GraphQL thread resolution. Import owner-verified thread facts.', 'note'); }
        else { notes(reviews, item.threads, function (li, t) {
            append(li, 'span', (t.resolved === false ? 'UNRESOLVED' : t.resolved === true ? 'resolved' : 'unknown') +
                (t.outdated === true ? ' · outdated' : '') + ' · ' + t.path + ' · ' + t.text);
        }); }

        var artifacts = section(detail, 'Run artifacts');
        notes(artifacts, item.artifacts, function (li, a) { link(li, a.name, a.url); });

        var blocks = section(detail, 'Blockers, dependencies and next step');
        notes(blocks, item.blockers, function (li, b) { append(li, 'span', b); });
        append(blocks, 'h4', 'Declared prerequisites (not inferred from mentions)');
        notes(blocks, item.dependsOn, function (li, dep) {
            var pieces = dep.split('#');
            link(li, dep, 'https://github.com/' + pieces[0] + '/issues/' + pieces[1]);
            append(li, 'span', state.items.has(dep) ? ' · observed: ' + status(state.items.get(dep)) : ' · not observed', 'small muted');
        });
        append(blocks, 'h4', 'Related work (not prerequisite)');
        notes(blocks, item.relatedTo, function (li, dep) {
            var pieces = dep.split('#');
            link(li, dep, 'https://github.com/' + pieces[0] + '/issues/' + pieces[1]);
        });
        append(blocks, 'p', 'Proposed investigation: ' + nextStep(item));
        append(blocks, 'p', 'Human approval: required for governed Contract decisions, independent review and release/merge policy. GitHub observation can be refreshed automatically. No authority is inferred.', 'note');
        if (item.kind === 'pr') {
            var button = append(blocks, 'button', 'Load PR checks, reviews and artifacts', 'btn');
            button.type = 'button';
            button.addEventListener('click', function () { loadDetails(item.id); });
        }
    }

    function renderCompare() {
        comparison.replaceChildren();
        var selected = Array.from(state.checked).map(function (id) { return state.items.get(id); }).filter(Boolean).slice(0, 4);
        comparison.hidden = selected.length < 2;
        if (selected.length < 2) {
            message.textContent = 'Select at least two work items for comparison (up to four).';
            return;
        }
        append(comparison, 'h2', 'Comparison · current observations only');
        var table = append(comparison, 'table', null, 'table');
        var tbody = append(table, 'tbody');
        [['Item', function (i) { return i.id; }],
            ['Head', function (i) { return i.head || 'unknown'; }],
            ['Claim', function (i) { return i.claim || 'unknown'; }],
            ['Freshness', freshness], ['Status', status], ['Next step', nextStep],
            ['Dependencies', function (i) { return i.dependsOn.join(', ') || 'unverified'; }],
            ['Related', function (i) { return i.relatedTo.join(', ') || 'none observed'; }]
        ].forEach(function (row) {
            var tr = append(tbody, 'tr');
            append(tr, 'th', row[0]);
            selected.forEach(function (item) { append(tr, 'td', row[1](item)); });
        });
    }

    function render() {
        renderList();
        if (state.selected) { renderDetail(); }
    }

    function api(path) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 12000);
        return fetch('https://api.github.com' + path, {
            headers: { Accept: 'application/vnd.github+json' },
            cache: 'no-store',
            credentials: 'omit',
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok) { throw new Error('GitHub API HTTP ' + response.status + ' for ' + path); }
            return response.json();
        }).finally(function () { clearTimeout(timer); });
    }

    function updateFromGitHub(raw, repo, kind, seenAt) {
        var id = repo + '#' + raw.number;
        var existing = state.items.get(id);
        var head = kind === 'pr' ? (raw.head && raw.head.sha || null) : null;
        var sameHead = existing && existing.head === head;
        var item = normalize({
            repo: repo, number: raw.number, kind: kind, title: raw.title,
            state: raw.merged_at ? 'merged' : raw.state,
            head: head, draft: raw.draft,
            claim: typeof raw.body === 'string' ? raw.body.slice(0, 4000) : null,
            phase: sameHead ? existing.phase : null,
            // Keep old SHA-bound proof as historical evidence, never current
            // authority. Drop thread disposition on any head transition.
            proofs: existing ? existing.proofs : [],
            reviews: existing ? existing.reviews : [],
            threads: sameHead ? existing.threads : null,
            artifacts: sameHead ? existing.artifacts : null,
            blockers: sameHead ? existing.blockers : [],
            dependsOn: existing ? existing.dependsOn : [],
            relatedTo: existing ? existing.relatedTo : [],
            observedAt: seenAt, detailsLoaded: false
        });
        // A head change preserves SHA-bound historical receipts as STALE, but
        // invalidates old thread disposition and head-unbound artifacts.
        state.items.set(id, item);
    }

    async function refresh() {
        if (state.refreshing) { return; }
        state.refreshing = true;
        document.getElementById('gh-refresh').disabled = true;
        message.textContent = 'Fetching public GitHub PR and issue inventories; a failed source stays unavailable.';
        var seenAt = new Date().toISOString();
        var failures = [];
        var successes = 0;
        try {
            for (var repo of repositories) {
                var settled = await Promise.allSettled([
                    api('/repos/' + repo + '/pulls?state=open&per_page=100'),
                    api('/repos/' + repo + '/issues?state=open&per_page=100')
                ]);
                if (settled[0].status === 'fulfilled') {
                    settled[0].value.forEach(function (x) { updateFromGitHub(x, repo, 'pr', seenAt); });
                    successes++;
                } else { failures.push(repo + ' PRs: ' + settled[0].reason.message); }
                if (settled[1].status === 'fulfilled') {
                    settled[1].value.filter(function (x) { return !x.pull_request; })
                        .forEach(function (x) { updateFromGitHub(x, repo, 'issue', seenAt); });
                    successes++;
                } else { failures.push(repo + ' issues: ' + settled[1].reason.message); }
                render();
            }
            state.updatedAt = seenAt;
            document.getElementById('gh-updated').textContent = 'Last inventory attempt: ' + seenAt;
            message.textContent = successes + '/12 inventories loaded. ' +
                (failures.length ? 'Unavailable (not empty): ' + failures.join('; ') : 'Open inventories observed. Detailed proof still requires checking.');
            nextRefresh = Date.now() + 30 * 60 * 1000;
        } finally {
            state.refreshing = false;
            document.getElementById('gh-refresh').disabled = false;
        }
    }

    async function loadDetails(id) {
        var item = state.items.get(id);
        if (!item || item.kind !== 'pr') { return; }
        var loadId = ++detailSequence;
        detailPending.set(id, loadId);
        var startHead = item.head;
        // Revoke same-head approvals and checks before attempting verification.
        // Clearly old-head receipts remain visible as stale history only.
        item.proofs = item.proofs.filter(function (p) { return p.sha && p.sha !== item.head; });
        item.reviews = [];
        item.threads = null;
        item.artifacts = null;
        item.detailsLoaded = false;
        item.partial = true;
        render();
        message.textContent = 'Loading current PR identity, checks, reviews and artifacts for ' + id + '.';
        var base = '/repos/' + item.repo;
        try {
            var pr = await api(base + '/pulls/' + item.number);
            var currentHead = pr.head && pr.head.sha;
            if (!/^[a-f0-9]{40}$/.test(currentHead || '')) { throw new Error('GitHub returned no exact PR head.'); }
            if (detailPending.get(id) !== loadId) { return; }
            var observed = state.items.get(id);
            if (!observed || (observed !== item && observed.head !== startHead)) {
                message.textContent = 'PR head changed during detail load. Discarded stale response; reload exact-head evidence.';
                render();
                return;
            }
            updateFromGitHub(pr, item.repo, 'pr', new Date().toISOString());
            item = state.items.get(id);
            var requests = await Promise.allSettled([
                api(base + '/pulls/' + item.number + '/reviews?per_page=100'),
                api(base + '/commits/' + currentHead + '/check-runs?per_page=100'),
                api(base + '/actions/runs?head_sha=' + currentHead + '&per_page=100'),
                api(base + '/pulls/' + item.number + '/comments?per_page=100')
            ]);
            // Fetch artifacts before applying any result: a concurrent inventory
            // refresh may replace the item object or observe a different head.
            var receivedRuns = requests[2].status === 'fulfilled' ?
                (requests[2].value.workflow_runs || []).filter(function (run) {
                    return run.head_sha === currentHead;
                }) : [];
            var artifactResponses = await Promise.allSettled(receivedRuns.slice(0, 3).map(function (run) {
                return api(base + '/actions/runs/' + run.id + '/artifacts?per_page=100');
            }));
            if (detailPending.get(id) !== loadId) { return; }
            var live = state.items.get(id);
            if (!live || live.head !== currentHead) {
                message.textContent = 'PR head changed during detail load. Discarded stale response; reload exact-head evidence.';
                render();
                return;
            }
            item = live;
            var missing = [];
            if (requests[0].status === 'fulfilled') {
                item.reviews = requests[0].value.map(function (r) {
                    return { state: r.state, author: r.user && r.user.login || 'unknown', sha: r.commit_id };
                });
            } else { missing.push('reviews'); }
            if (requests[1].status === 'fulfilled') {
                var checks = requests[1].value.check_runs || [];
                var previous = item.proofs.filter(function (p) { return p.sha && p.sha !== currentHead; });
                item.proofs = previous.concat(checks.map(function (c) {
                    return { type: /dogfood|consumer|smoke|host/i.test(c.name) ? 'dogfood' : 'ci',
                        sha: c.head_sha || currentHead, outcome: c.conclusion || c.status,
                        label: c.name, url: c.html_url, observedAt: c.completed_at };
                })).slice(-100);
            } else { missing.push('check runs'); }
            var runs = [];
            if (requests[2].status === 'fulfilled') {
                runs = (requests[2].value.workflow_runs || []).filter(function (r) {
                    return r.head_sha === currentHead;
                });
                runs.forEach(function (r) {
                    item.proofs.push({ type: /dogfood|consumer|smoke|host/i.test(r.name) ? 'dogfood' : 'ci',
                        sha: r.head_sha, outcome: r.conclusion || r.status,
                        label: r.name, url: r.html_url, observedAt: r.updated_at });
                });
            } else { missing.push('workflow runs'); }
            if (requests[3].status !== 'fulfilled') { missing.push('inline review comments'); }
            // The REST comments endpoint does not provide resolved-thread state.
            // Never turn a zero-comment count into "no open review threads".
            item.threads = null;
            item.artifacts = [];
            artifactResponses.forEach(function (result, index) {
                if (result.status !== 'fulfilled') { missing.push('run artifact ' + runs[index].id); return; }
                (result.value.artifacts || []).forEach(function (a) {
                    item.artifacts.push({ name: a.name, url: runs[index].html_url + '#artifacts' });
                });
            });
            item.detailsLoaded = true;
            item.partial = missing.length > 0;
            message.textContent = 'Exact-head details loaded for ' + id + '. ' +
                (missing.length ? 'Unverified: ' + missing.join(', ') + '. ' : '') +
                'Thread resolution still requires a verified GraphQL/source import.';
        } catch (error) {
            if (detailPending.get(id) === loadId) {
                message.textContent = 'Details unavailable for ' + id + ': ' + error.message +
                    '. Same-head checks and reviews were cleared and are unverified; no success inferred.';
            }
        }
        render();
    }

    function select(id) {
        state.selected = id;
        renderDetail();
    }

    function exportJson() {
        var payload = { schemaVersion: 1, generatedAt: new Date().toISOString(),
            source: 'agent-ui GitHub observations; claims are not owner authority',
            items: Array.from(state.items.values()) };
        var blob = new Blob([JSON.stringify(payload, null, 2) + '\n'], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = 'agent-workflow-evidence.json';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    async function importJson(event) {
        var file = event.target.files && event.target.files[0];
        if (!file) { return; }
        try {
            if (file.size > 2 * 1024 * 1024) { throw new Error('Snapshot exceeds the 2 MiB limit.'); }
            var snapshot = JSON.parse(await file.text());
            if (snapshot.schemaVersion !== 1 || !Array.isArray(snapshot.items) || snapshot.items.length > 300) {
                throw new Error('Expected schemaVersion 1 with at most 300 items.');
            }
            var candidate = snapshot.items.map(normalize);
            candidate.forEach(function (item) {
                // An imported snapshot is evidence supplied by the operator, not
                // a live GitHub or agent-loop authorization receipt.
                state.items.set(item.id, item);
            });
            message.textContent = 'Imported ' + candidate.length + ' work items. Verify imported sources before acting.';
            render();
        } catch (error) {
            message.textContent = 'Import rejected: ' + error.message + '. No imported entries were applied.';
        } finally {
            event.target.value = '';
        }
    }

    repositories.forEach(function (repo) {
        var opt = append(document.getElementById('gh-repo'), 'option', repo);
        opt.value = repo;
    });
    controls.forEach(function (id) {
        document.getElementById('gh-' + id).addEventListener(id === 'query' ? 'input' : 'change', renderList);
    });
    document.getElementById('gh-refresh').addEventListener('click', refresh);
    document.getElementById('gh-export').addEventListener('click', exportJson);
    document.getElementById('gh-import-button').addEventListener('click', function () {
        document.getElementById('gh-import').click();
    });
    document.getElementById('gh-import').addEventListener('change', importJson);
    document.getElementById('gh-compare').addEventListener('click', renderCompare);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && Date.now() >= nextRefresh) { refresh(); }
    });
    setInterval(function () {
        if (!document.hidden && Date.now() >= nextRefresh) { refresh(); }
    }, 60 * 1000);
    refresh();
})();
