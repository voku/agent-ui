'use strict';

const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = process.argv[2] || 'http://127.0.0.1:8080';
const HEAD_A = 'a'.repeat(40);
const HEAD_B = 'b'.repeat(40);
let head = HEAD_A;
let detailApiUnavailable = false;
let detailPrUnavailable = false;
let checkOutcome = 'success';
let extraCheckOutcomes = [];
let reviewerStates = ['APPROVED'];
let holdReviews = false;
let reviewGateEntered = null;
let releaseReviews = null;
const browserOptions = { headless: true };
if (process.env.CHROMIUM_PATH) {
    browserOptions.executablePath = process.env.CHROMIUM_PATH;
    browserOptions.args = ['--no-sandbox'];
}

(async function () {
    const browser = await chromium.launch(browserOptions);
    try {
        const disabled = await browser.newContext({ javaScriptEnabled: false });
        const noJsPage = await disabled.newPage();
        await noJsPage.goto(base + '/github-workflow');
        assert.equal(await noJsPage.locator('#github-workflow').isVisible(), false);
        assert.ok((await noJsPage.content()).includes('This optional cross-repository view requires JavaScript'));
        await disabled.close();

        const context = await browser.newContext({ acceptDownloads: true });
        const page = await context.newPage();
        const failures = [];
        page.on('pageerror', error => failures.push(error.message));

        await page.route('https://api.github.com/**', async route => {
            const url = new URL(route.request().url());
            const path = url.pathname;
            const repo = path.match(/^\/repos\/voku\/([^/]+)/)?.[1];
            let result = {};
            if ((detailPrUnavailable && path.endsWith('/pulls/500')) ||
                (detailApiUnavailable &&
                (path.endsWith('/pulls/500/reviews') || path.endsWith('/pulls/500/comments') ||
                    path.endsWith('/actions/runs') || /\/commits\/[a-f0-9]{40}\/check-runs$/.test(path)))) {
                await route.fulfill({ status: 503, contentType: 'application/json',
                    body: JSON.stringify({ message: 'Temporary GitHub API failure' }) });
                return;
            }
            if (path.endsWith('/pulls') && url.searchParams.get('state') === 'open') {
                result = repo === 'agent-loop' ? [{
                    number: 500, title: 'fix: meaningful example', body: 'Claim: fixes a real bug',
                    state: 'open', draft: false, head: { sha: head }
                }] : [];
            } else if (path.endsWith('/issues') && url.searchParams.get('state') === 'open') {
                result = repo === 'agent-loop' ? [{
                    number: 501, title: 'Release then verify consumer', body: 'Owner issue, not proof',
                    state: 'open'
                }] : [];
            } else if (path.endsWith('/pulls/500')) {
                result = {
                    number: 500, title: 'fix: meaningful example', body: 'Claim: fixes a real bug',
                    state: 'open', draft: false, head: { sha: head }
                };
            } else if (path.endsWith('/pulls/500/reviews')) {
                if (holdReviews) {
                    if (reviewGateEntered) { reviewGateEntered(); }
                    await new Promise(resolve => { releaseReviews = resolve; });
                }
                result = reviewerStates.map(state => ({
                    state, user: { login: 'reviewer' }, commit_id: head
                }));
            } else if (/\/commits\/[a-f0-9]{40}\/check-runs$/.test(path)) {
                result = { check_runs: [checkOutcome].concat(extraCheckOutcomes).map((outcome, index) => ({
                    name: index === 0 ? 'CI' : 'CI extra ' + index,
                    head_sha: head, conclusion: outcome === 'in_progress' ? null : outcome,
                    status: outcome === 'in_progress' ? 'in_progress' : 'completed',
                    html_url: 'https://github.com/voku/agent-loop/actions/runs/' + (index + 1)
                })) };
            } else if (path.endsWith('/actions/runs')) {
                result = { workflow_runs: [] };
            } else if (path.endsWith('/pulls/500/comments')) {
                result = [];
            } else {
                throw new Error('Unexpected external request in bounded browser fixture: ' + path);
            }
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(result) });
        });

        const response = await page.goto(base + '/github-workflow');
        assert.equal(response.status(), 200);
        await page.waitForFunction(() => document.querySelector('#gh-count').textContent.includes('2 of 2'));
        assert.equal(await page.locator('#gh-list tr').count(), 2);
        await page.getByRole('button', { name: /agent-loop#500/ }).click();
        await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes('Exact-head details loaded'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('CURRENT HEAD'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('Claim (not proof)'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('resolved/unresolved thread state UNKNOWN') ||
            (await page.locator('#gh-detail').innerText()).includes('thread state UNKNOWN'));

        // When the PR lookup itself fails, cleared same-head observations must
        // not be described as retained. No prior approval or CI may survive.
        detailPrUnavailable = true;
        await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes(
            'Same-head checks and reviews were cleared and are unverified'));
        assert.ok(!(await page.locator('#gh-detail').innerText()).includes('CURRENT HEAD'));
        detailPrUnavailable = false;

        // A retry with four unavailable detail APIs must not inherit
        // the earlier same-head approval or successful check.
        detailApiUnavailable = true;
        await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes(
            'Unverified: reviews, check runs, workflow runs, inline review comments'));
        const unavailableDetail = await page.locator('#gh-detail').innerText();
        assert.ok(unavailableDetail.includes('Not verified / not available.'));
        assert.ok(!unavailableDetail.includes('CURRENT HEAD'));
        assert.ok(!unavailableDetail.includes('APPROVED by reviewer'));
        detailApiUnavailable = false;

        // Failing conclusions must neither satisfy the CI-proof filter nor
        // allow an older approval to override a later change request.
        checkOutcome = 'timed_out';
        reviewerStates = ['APPROVED', 'CHANGES_REQUESTED'];
        await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes('Exact-head details loaded'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('state: blocked'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('timed_out'));
        await page.locator('#gh-proof').selectOption('ci');
        assert.equal(await page.locator('#gh-list tr').count(), 1); // Empty placeholder row, not a proof.
        assert.ok((await page.locator('#gh-list').innerText()).includes('No matching observations'));
        await page.locator('#gh-proof').selectOption('');

        // A passing check must not mask additional pending, neutral or skipped
        // exact-head checks. Every fresh check must have a success outcome.
        checkOutcome = 'success';
        reviewerStates = ['APPROVED'];
        for (const nonPassingOutcome of ['in_progress', 'neutral', 'skipped']) {
            extraCheckOutcomes = [nonPassingOutcome];
            await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
            await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes('Exact-head details loaded'));
            await page.locator('#gh-proof').selectOption('ci');
            assert.ok((await page.locator('#gh-list').innerText()).includes('No matching observations'));
            await page.locator('#gh-proof').selectOption('');
            assert.ok((await page.locator('#gh-detail').innerText()).includes('Collect current-head CI jobs and their outcomes'));
        }
        // Multiple passing same-head checks must show the actual matching PR.
        // A single "No matching observations" placeholder row is not proof.
        extraCheckOutcomes = ['success'];
        await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes('Exact-head details loaded'));
        await page.locator('#gh-proof').selectOption('ci');
        assert.equal(await page.locator('#gh-list tr').count(), 1);
        assert.equal(await page.locator('#gh-list').getByRole('button', { name: /agent-loop#500/ }).count(), 1);
        assert.ok(!(await page.locator('#gh-list').innerText()).includes('No matching observations'));
        await page.locator('#gh-proof').selectOption('');
        extraCheckOutcomes = [];

        // Dismissing the latest review cannot resurrect the earlier approval.
        checkOutcome = 'success';
        reviewerStates = ['APPROVED', 'DISMISSED'];
        await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes('Exact-head details loaded'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('state: review'));

        // Race: a newer inventory head arrives while detail requests wait.
        // Their late response must not repaint the replaced item or claim proof.
        holdReviews = true;
        const reviewStarted = new Promise(resolve => { reviewGateEntered = resolve; });
        await page.getByRole('button', { name: 'Load PR checks, reviews and artifacts' }).click();
        await reviewStarted;
        head = HEAD_B;
        await page.locator('#gh-refresh').click();
        await page.waitForFunction(sha => document.querySelector('#gh-detail').textContent.includes(sha), HEAD_B);
        assert.ok(typeof releaseReviews === 'function');
        holdReviews = false;
        releaseReviews();
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes('Discarded stale response'));
        assert.ok(!(await page.locator('#gh-detail').innerText()).includes('CURRENT HEAD'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('Not verified / not available.'));

        const chooserPromise = page.waitForEvent('filechooser');
        await page.getByRole('button', { name: 'Import JSON' }).click();
        const chooser = await chooserPromise;
        await chooser.setFiles({
            name: 'snapshot.json', mimeType: 'application/json',
            buffer: Buffer.from(JSON.stringify({
                schemaVersion: 1, items: [{
                    repo: 'voku/agent-loop', number: 500, kind: 'pr',
                    title: 'fix: meaningful example', head: HEAD_B, state: 'open', draft: false,
                    claim: 'Imported claim', proofs: [{
                        type: 'ci', sha: HEAD_A, outcome: 'success',
                        label: 'Old head CI', url: 'https://github.com/voku/agent-loop/actions/runs/1'
                    }],
                    dependsOn: ['voku/agent-loop#501'],
                    threads: [{ resolved: false, text: 'Must address reviewer request', path: 'src/Example.php' }]
                }]
            }))
        });
        await page.waitForFunction(() => document.querySelector('#gh-message').textContent.includes('Imported 1 work items'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('STALE / UNKNOWN'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('UNRESOLVED'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('Inspect upstream voku/agent-loop#501 first'));
        assert.ok((await page.locator('#gh-detail').innerText()).includes('Declared prerequisites'));

        await page.locator('#gh-kind').selectOption('issue');
        assert.equal(await page.locator('#gh-list tr').count(), 1);
        await page.locator('#gh-kind').selectOption('');
        await page.locator('#gh-list input[type=checkbox]').first().check();
        await page.locator('#gh-list input[type=checkbox]').last().check();
        await page.locator('#gh-compare').click();
        assert.equal(await page.locator('#gh-comparison').isVisible(), true);
        assert.ok((await page.locator('#gh-comparison th').allTextContents()).includes('Dependencies'));

        const downloadPromise = page.waitForEvent('download');
        await page.locator('#gh-export').click();
        const download = await downloadPromise;
        assert.equal(download.suggestedFilename(), 'agent-workflow-evidence.json');
        assert.deepEqual(failures, []);
        await context.close();
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
