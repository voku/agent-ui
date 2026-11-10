'use strict';

const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = process.argv[2] || 'http://127.0.0.1:8080';
const captureGraph = () => {
    let wrapped;
    Object.defineProperty(window, 'cytoscape', {
        configurable: true,
        get: () => wrapped,
        set: (factory) => {
            wrapped = (options) => {
                const graph = factory(options);
                window.__workflowTestGraph = graph;
                return graph;
            };
        }
    });
};

(async () => {
    const options = { headless: true };
    if (process.env.CHROMIUM_PATH) {
        options.executablePath = process.env.CHROMIUM_PATH;
        options.args = ['--no-sandbox'];
    }
    const browser = await chromium.launch(options);
    try {
        const staticContext = await browser.newContext({ javaScriptEnabled: false });
        const staticPage = await staticContext.newPage();
        await staticPage.goto(base + '/task/UI-36/progress');
        assert.ok(await staticPage.locator('[data-progress-list] > li').count() > 0);
        assert.equal(await staticPage.locator('[data-workflow-decision] button[type=submit]').count(), 1);
        assert.equal(await staticPage.locator('[data-workflow-brief]').count(), 1);
        await staticPage.locator('[data-workflow-brief]').getByText('Contract authority').waitFor();
        assert.equal(await staticPage.locator('[data-workflow-brief]').getByText('Not approved · revision 1').count(), 1);
        assert.equal(await staticPage.locator('[data-workflow-live-status]').isVisible(), false);

        // No-JS path: the browser renders owner Attention, performs a native
        // form POST, and re-enters the task from fresh canonical state.
        const staticAttention = await staticContext.newPage();
        await staticAttention.goto(base + '/task/UI-39/progress');
        const staticForm = staticAttention.locator('form[action="/task/UI-39/attention"]');
        assert.equal(await staticForm.count(), 1);
        assert.equal(await staticAttention.getByText('Browser fixture needs a human clarification.').count(), 1);
        const staticAttentionId = await staticForm.locator('[name="attention_id"]').inputValue();
        const staticCsrf = await staticForm.locator('[name="_csrf"]').inputValue();
        await staticForm.locator('[name="actor"]').fill('browser-no-js-human');
        const [staticNavigation] = await Promise.all([
            staticAttention.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            staticForm.getByRole('button', { name: 'Resolve Attention' }).click()
        ]);
        assert.equal(staticNavigation.status(), 200);
        assert.equal(await staticAttention.locator('form[action="/task/UI-39/attention"]').count(), 0);
        await staticAttention.reload();
        assert.equal(await staticAttention.locator('form[action="/task/UI-39/attention"]').count(), 0);
        const staleNoJs = await staticContext.request.post(base + '/task/UI-39/attention', {
            form: { _csrf: staticCsrf, attention_id: staticAttentionId, actor: 'browser-no-js-human' },
            maxRedirects: 0
        });
        assert.equal(staleNoJs.status(), 400);
        await staticContext.close();

        const mobileContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const mobilePage = await mobileContext.newPage();
        await mobilePage.addInitScript(captureGraph);
        await mobilePage.goto(base + '/task/UI-36/progress');
        await mobilePage.waitForFunction(() => window.__workflowTestGraph && window.__workflowTestGraph.nodes().length > 0);
        assert.ok(await mobilePage.evaluate(() => window.__workflowTestGraph.zoom()) >= 0.75);
        await mobileContext.close();

        const context = await browser.newContext();
        const page = await context.newPage();
        // Capture the real Cytoscape instance without adding a test hook to the application.
        await page.addInitScript(captureGraph);
        await page.goto(base + '/task/UI-36/progress');
        await page.waitForFunction(() => document.querySelector('[data-workflow-live-status]').textContent === 'Live workflow updates connected.');
        await page.waitForFunction(() => window.__workflowTestGraph && window.__workflowTestGraph.nodes().length > 0);
        const actorInput = page.locator('[data-workflow-decision] input[name="actor"]');
        await actorInput.fill('unfinished reviewer');
        const refresh = page.waitForResponse((response) => response.url().endsWith('/progress.json'));
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await refresh;
        assert.equal(await actorInput.inputValue(), 'unfinished reviewer');

        const graphBefore = await page.evaluate(() => {
            const graph = window.__workflowTestGraph;
            graph.nodes().unselect();
            const node = graph.nodes()[0];
            node.select();
            node.position({ x: 270, y: 145 });
            graph.zoom(1.25);
            graph.pan({ x: 45, y: 35 });
            return { step: node.data('step_id'), position: node.position(), zoom: graph.zoom(), pan: graph.pan() };
        });
        const originalDigest = await page.locator('[data-workflow-decision]').getAttribute('data-decision-digest');
        const originalBriefDigest = await page.locator('[data-workflow-brief]').getAttribute('data-brief-digest');
        const originalAction = await page.locator('[data-task-next-action]').textContent();
        const csrf = await page.locator('[data-workflow-decision] input[name="_csrf"]').inputValue();
        const approval = await context.request.post(base + '/task/UI-36/approve', {
            form: { _csrf: csrf, actor: 'browser-fixture', return_to: 'progress', revision: '1' },
            maxRedirects: 0
        });
        assert.equal(approval.status(), 303);
        await page.waitForFunction((digest) => document.querySelector('[data-workflow-decision]').getAttribute('data-decision-digest') !== digest, originalDigest, { timeout: 15000 });
        assert.equal(await page.locator('[data-workflow-decision] button[type=submit]').count(), 0);
        assert.notEqual(await page.locator('[data-task-next-action]').textContent(), originalAction);
        await page.waitForFunction((digest) => document.querySelector('[data-workflow-brief]').getAttribute('data-brief-digest') !== digest, originalBriefDigest);
        assert.equal(await page.locator('[data-workflow-brief]').getByText('Approved revision 1').count(), 1);
        assert.equal(await page.locator('[data-workflow-brief] a[href="#workflow-approval"]').count(), 0);

        const graphAfter = await page.evaluate((stepId) => {
            const graph = window.__workflowTestGraph;
            const node = graph.nodes().filter((item) => item.data('step_id') === stepId)[0];
            return node && { selected: node.selected(), position: node.position(), zoom: graph.zoom(), pan: graph.pan() };
        }, graphBefore.step);
        assert.ok(graphAfter && graphAfter.selected);
        assert.deepEqual(graphAfter.position, graphBefore.position);
        assert.equal(graphAfter.zoom, graphBefore.zoom);
        assert.deepEqual(graphAfter.pan, graphBefore.pan);

        // Exercise a changed owner step set as well as changed status values.
        const currentResponse = await context.request.get(base + '/task/UI-36/progress.json');
        const reordered = await currentResponse.json();
        reordered.steps = reordered.steps.slice().reverse();
        reordered.steps.push({ id: 'future-step', label: 'Future step', status: 'pending', status_label: 'pending', owner: 'agent-loop', reason: null });
        await page.route('**/progress.json', (route) => route.fulfill({ json: reordered }));
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(() => window.__workflowTestGraph.nodes().some((node) => node.data('step_id') === 'future-step'), null, { timeout: 15000 });
        const reorderedGraph = await page.evaluate((stepId) => {
            const graph = window.__workflowTestGraph;
            const node = graph.nodes().filter((item) => item.data('step_id') === stepId)[0];
            return node && { selected: node.selected(), position: node.position(), zoom: graph.zoom(), pan: graph.pan() };
        }, graphBefore.step);
        assert.ok(reorderedGraph && reorderedGraph.selected);
        assert.deepEqual(reorderedGraph.position, graphBefore.position);
        assert.equal(reorderedGraph.zoom, graphBefore.zoom);
        assert.deepEqual(reorderedGraph.pan, graphBefore.pan);
        await page.unroute('**/progress.json');

        const lastAction = await page.locator('[data-task-next-action]').textContent();
        await page.route('**/progress.json', (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{"error":"unavailable"}' }));
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForFunction(() => document.querySelector('[data-workflow-live-status]').textContent.includes('unavailable'), null, { timeout: 15000 });
        assert.equal(await page.locator('[data-task-next-action]').textContent(), lastAction);
        await page.unroute('**/progress.json');
        await page.getByRole('button', { name: 'Retry updates' }).click();
        await page.waitForFunction(() => document.querySelector('[data-workflow-live-status]').textContent === 'Live workflow updates connected.');

        // Simulate an empty owner projection, then let a real Contract create a timeline.
        const emptyPage = await context.newPage();
        await emptyPage.addInitScript(captureGraph);
        const emptyResponse = await context.request.get(base + '/task/UI-37/progress.json');
        const emptyProjection = await emptyResponse.json();
        emptyProjection.steps = [];
        await emptyPage.route('**/progress.json', (route) => route.fulfill({ json: emptyProjection }));
        await emptyPage.goto(base + '/task/UI-37/progress');
        await emptyPage.waitForFunction(() => document.querySelector('[data-workflow-live-status]').textContent === 'Live workflow updates connected.');
        assert.equal(await emptyPage.locator('[data-progress-empty]').isVisible(), true);
        assert.equal(await emptyPage.locator('[data-workflow-graph]').isVisible(), false);
        await emptyPage.waitForFunction(() => window.__workflowTestGraph && window.__workflowTestGraph.nodes().length === 0);
        await emptyPage.evaluate(() => { window.__pageMarker = 'same-document'; });
        await emptyPage.unroute('**/progress.json');
        const editor = await context.newPage();
        await editor.goto(base + '/task/UI-37/contract');
        const editorCsrf = await editor.locator('form input[name="_csrf"]').first().inputValue();
        const proposed = await context.request.post(base + '/task/UI-37/contract', {
            form: {
                _csrf: editorCsrf,
                contract_action: 'propose',
                goal: 'Start an owner-backed workflow',
                scope: 'src/Feature/Task',
                validation: 'composer ci',
                planned_by: 'browser-fixture'
            },
            maxRedirects: 0
        });
        assert.equal(proposed.status(), 303);
        await emptyPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await emptyPage.waitForFunction(() => document.querySelectorAll('[data-progress-list] > li').length > 0 &&
            !document.querySelector('[data-workflow-graph]').hidden &&
            document.querySelector('[data-workflow-decision] button[type=submit]'), null, { timeout: 15000 });
        assert.equal(await emptyPage.evaluate(() => window.__pageMarker), 'same-document');
        assert.ok((await emptyPage.locator('[data-task-decision-link] a').getAttribute('href')).endsWith('/task/UI-37#decision'));

        // Regression: a Cytoscape instance created while its container was
        // initially hidden must become visible and interactive without a reload.
        await emptyPage.waitForFunction(() => window.__workflowTestGraph &&
            window.__workflowTestGraph.nodes().length > 0);
        assert.equal(await emptyPage.locator('[data-workflow-graph]').isVisible(), true);
        const initialReveal = await emptyPage.evaluate(() => {
            const cy = window.__workflowTestGraph;
            const rect = document.querySelector('[data-workflow-canvas]').getBoundingClientRect();
            return { width: cy.width(), height: cy.height(), rectWidth: rect.width,
                nodes: cy.nodes().length, selected: cy.nodes(':selected').length };
        });
        assert.ok(initialReveal.nodes > 0, 'graph receives the new owner steps');
        assert.ok(initialReveal.width > 100 && initialReveal.height > 100 &&
            initialReveal.rectWidth > 100, 'formerly hidden Cytoscape canvas becomes sized');
        assert.ok(initialReveal.selected > 0, 'active node is selected after first reveal');
        await emptyPage.locator('[data-wf-zoom="in"]').click();
        const zoomAfterReveal = await emptyPage.evaluate(() => window.__workflowTestGraph.zoom());
        assert.ok(zoomAfterReveal > 0, 'graph remains interactive after first reveal');

        const nowPopulated = await (await context.request.get(base + '/task/UI-37/progress.json')).json();
        await emptyPage.route('**/progress.json', (route) => route.fulfill({ json: { ...nowPopulated, steps: [] } }));
        await emptyPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await emptyPage.waitForFunction(() => window.__workflowTestGraph.nodes().length === 0);
        assert.equal(await emptyPage.locator('[data-workflow-graph]').isVisible(), false);
        await emptyPage.unroute('**/progress.json');
        await emptyPage.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await emptyPage.waitForFunction(() => window.__workflowTestGraph.nodes().length > 0);
        assert.equal(await emptyPage.locator('[data-workflow-graph]').isVisible(), true);
        assert.ok(await emptyPage.evaluate(() => window.__workflowTestGraph.width() > 100));
        await editor.close();

        // JavaScript-enhanced path: click the real decision form, observe the
        // live owner refresh, then verify stale and invalid posts stay rejected.
        const attentionPage = await context.newPage();
        await attentionPage.goto(base + '/task/UI-38/progress');
        await attentionPage.waitForFunction(() => document.querySelector('[data-workflow-live-status]').textContent === 'Live workflow updates connected.');
        const attentionForm = attentionPage.locator('form[action="/task/UI-38/attention"]');
        assert.equal(await attentionForm.count(), 1);
        assert.equal(await attentionPage.getByText('Browser fixture needs a human clarification.').count(), 1);
        const attentionId = await attentionForm.locator('[name="attention_id"]').inputValue();
        const attentionCsrf = await attentionForm.locator('[name="_csrf"]').inputValue();
        const pending = await (await context.request.get(base + '/task/UI-38/progress.json')).json();
        assert.equal(pending.has_human_decision, true);
        assert.ok(pending.decision_html.includes(attentionId));

        const rejected = await context.request.post(base + '/task/UI-38/attention', {
            form: { _csrf: 'invalid', attention_id: attentionId, actor: 'browser-human' },
            maxRedirects: 0
        });
        assert.equal(rejected.status(), 400);
        assert.equal(await attentionForm.count(), 1);

        await attentionForm.locator('[name="actor"]').fill('browser-human');
        await attentionForm.getByRole('button', { name: 'Resolve Attention' }).click();
        await attentionPage.waitForFunction(() =>
            !document.querySelector('form[action="/task/UI-38/attention"]'), null, { timeout: 15000 });
        const resolved = await (await context.request.get(base + '/task/UI-38/progress.json')).json();
        assert.equal(resolved.has_human_decision, false);
        assert.ok(!resolved.decision_html.includes(attentionId));

        const stale = await context.request.post(base + '/task/UI-38/attention', {
            form: { _csrf: attentionCsrf, attention_id: attentionId, actor: 'browser-human' },
            maxRedirects: 0
        });
        assert.equal(stale.status(), 400);
        await attentionPage.reload();
        assert.equal(await attentionPage.locator('form[action="/task/UI-38/attention"]').count(), 0);
        assert.equal(await attentionPage.locator('[data-task-decision-link] a').count(), 0);
        await attentionPage.close();
        await context.close();

        console.log('PASS: owner changes, Attention resolution (JS/no-JS/stale/CSRF), empty-to-live graph/recovery, graph state retention, mobile zoom, unavailable/retry');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
