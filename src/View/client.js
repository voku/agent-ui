/* Progressive enhancement only: every command stays visible and selectable without it. */
function enhanceCopyButtons(root) {
    if (!navigator.clipboard) { return; }
    root.querySelectorAll('.copy').forEach(function (button) {
        if (button.dataset.copyReady === '1') { return; }
        button.dataset.copyReady = '1';
        var original = button.textContent;
        button.hidden = false;
        button.addEventListener('click', function () {
            var source = document.getElementById(button.dataset.copyTarget);
            if (!source || button.disabled) { return; }
            button.disabled = true;
            navigator.clipboard.writeText(source.textContent.trim()).then(function () {
                button.textContent = 'Copied';
                button.dataset.copied = '1';
                setTimeout(function () {
                    button.textContent = original;
                    delete button.dataset.copied;
                    button.disabled = false;
                }, 1500);
            }, function () {
                button.textContent = original;
                delete button.dataset.copied;
                button.disabled = false;
            });
        });
    });
}
enhanceCopyButtons(document);

/* Prompt Workbench.
 *
 * Without this the workbench is two full-page round trips: "Load selected
 * recipe" and "Generate". Here the recipe's owner-declared fields appear as
 * soon as a recipe is chosen, and the result region is refreshed in place by
 * POSTing the same form to the same endpoint for the server-rendered result
 * fragment. The browser composes nothing: every byte of the preview is what
 * Generate returns. */
(function () {
    var form = document.querySelector('[data-prompt-workbench]');
    if (!form || !window.fetch || !window.FormData || !window.URLSearchParams) { return; }
    var result = form.querySelector('[data-prompt-result]');
    var status = form.querySelector('[data-prompt-status]');
    var empty = form.querySelector('[data-prompt-empty]');
    var generateRow = form.querySelector('[data-prompt-generate-row]');
    var selectRow = form.querySelector('[data-prompt-select-row]');
    var fieldsets = Array.prototype.slice.call(form.querySelectorAll('[data-recipe-fields]'));
    if (!result) { return; }

    /* Choosing a recipe now applies immediately, so the round-trip button
       would only reload what is already on screen. */
    if (selectRow) { selectRow.hidden = true; }

    var timer = null;
    var controller = null;
    var sequence = 0;

    function say(message) { if (status) { status.textContent = message; } }

    function selectedRecipe() {
        var checked = form.querySelector('input[name="recipe"]:checked');
        return checked ? checked.value : '';
    }

    /* Disabled fieldsets are not submitted, so only the chosen recipe's
       arguments reach the owner even where two recipes share a name. */
    function showRecipe(recipeId) {
        fieldsets.forEach(function (fieldset) {
            var active = fieldset.getAttribute('data-recipe-fields') === recipeId;
            fieldset.hidden = !active;
            fieldset.disabled = !active;
        });
        if (empty) { empty.hidden = recipeId !== ''; }
        if (generateRow) { generateRow.hidden = recipeId === ''; }
    }

    /* A prompt for other inputs than the ones on screen must not stay
       copyable, so an out-of-date result is removed rather than left behind. */
    function clearResult(message) {
        sequence += 1;
        if (controller) { controller.abort(); controller = null; }
        result.innerHTML = '';
        result.removeAttribute('aria-busy');
        say(message);
    }

    function preview() {
        if (selectedRecipe() === '') { return; }
        if (!form.checkValidity()) {
            clearResult('Fill in the required fields to preview the prompt.');
            return;
        }
        var data = new FormData(form);
        data.set('action', 'generate');
        data.set('_fragment', 'result');
        if (controller) { controller.abort(); }
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        var mine = ++sequence;
        result.setAttribute('aria-busy', 'true');
        say('Updating preview…');
        fetch(form.getAttribute('action') || window.location.pathname, {
            method: 'POST',
            body: new URLSearchParams(data),
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html' },
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.text().then(function (html) { return { ok: response.ok, html: html }; });
        }).then(function (payload) {
            if (mine !== sequence) { return; }
            result.innerHTML = payload.html;
            result.removeAttribute('aria-busy');
            enhanceCopyButtons(result);
            say(payload.ok ? 'Preview is current.' : 'The owner refused this prompt; see why below.');
        }).catch(function (error) {
            if (error && error.name === 'AbortError') { return; }
            if (mine !== sequence) { return; }
            result.removeAttribute('aria-busy');
            say('Preview unavailable. Generate still works.');
        });
    }

    function schedule() {
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(preview, 350);
    }

    form.addEventListener('change', function (event) {
        var target = event.target;
        if (target instanceof HTMLInputElement && target.name === 'recipe') {
            showRecipe(target.value);
            clearResult('');
            preview();
            return;
        }
        schedule();
    });
    form.addEventListener('input', function (event) {
        if (event.target instanceof HTMLInputElement && event.target.name === 'recipe') { return; }
        schedule();
    });
    form.addEventListener('submit', function (event) {
        var submitter = event.submitter;
        if (!submitter || submitter.value !== 'generate') { return; }
        event.preventDefault();
        if (timer) { clearTimeout(timer); }
        preview();
        result.focus({ preventScroll: true });
    });

    showRecipe(selectedRecipe());
}());

/* Graph connection highlighting on node hover */
document.querySelectorAll('.graph-node-link').forEach(function (node) {
    var nodeId = node.dataset.nodeId;
    if (!nodeId) { return; }
    node.addEventListener('mouseenter', function () {
        document.querySelectorAll('.graph-edge').forEach(function (edge) {
            if (edge.dataset.source === nodeId || edge.dataset.target === nodeId) {
                edge.setAttribute('stroke', 'var(--accent)');
                edge.setAttribute('stroke-opacity', '0.9');
            } else {
                edge.setAttribute('stroke-opacity', '0.12');
            }
        });
    });
    node.addEventListener('mouseleave', function () {
        document.querySelectorAll('.graph-edge').forEach(function (edge) {
            edge.setAttribute('stroke', 'var(--ink-faint)');
            edge.setAttribute('stroke-opacity', '0.38');
        });
    });
});

/* Interactive map explorer.
 *
 * Progressive enhancement over the same server-rendered SVG: this only changes
 * the viewBox and toggles classes. It never reads a map artifact, computes a
 * layout, resolves a navigation target or edits the evidence tables, and the
 * detail panel it reveals was rendered by the server from the same snapshot. */
(function () {
    var explorer = document.querySelector('[data-graph-explorer]');
    if (!explorer) { return; }
    var svg = explorer.querySelector('[data-graph-viewport]');
    var toolbar = explorer.querySelector('[data-graph-toolbar]');
    if (!svg || !toolbar) { return; }

    var base = (svg.dataset.graphBaseView || '0 0 1000 700').split(/\s+/).map(Number);
    if (base.length !== 4 || base.some(isNaN)) { return; }
    var view = base.slice();
    var nodes = Array.prototype.slice.call(explorer.querySelectorAll('.graph-node-link'));
    var edges = Array.prototype.slice.call(explorer.querySelectorAll('.graph-edge'));
    var details = Array.prototype.slice.call(explorer.querySelectorAll('[data-graph-detail]'));
    var clearButton = toolbar.querySelector('[data-graph-clear]');
    var selected = null;

    toolbar.hidden = false;
    svg.classList.add('graph-explorer__svg--interactive');

    function applyView() {
        svg.setAttribute('viewBox', view.join(' '));
    }

    /* Zoom about a point so the thing under the pointer stays under it. */
    function zoom(factor, originX, originY) {
        var width = Math.min(base[2] * 4, Math.max(base[2] / 8, view[2] * factor));
        var scale = width / view[2];
        var height = view[3] * scale;
        view[0] = originX - (originX - view[0]) * scale;
        view[1] = originY - (originY - view[1]) * scale;
        view[2] = width;
        view[3] = height;
        applyView();
    }

    function viewPoint(event) {
        var rect = svg.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) {
            return { x: view[0] + view[2] / 2, y: view[1] + view[3] / 2 };
        }
        return {
            x: view[0] + ((event.clientX - rect.left) / rect.width) * view[2],
            y: view[1] + ((event.clientY - rect.top) / rect.height) * view[3]
        };
    }

    /* Node identities are owner strings — a file node's id is its repository
       path — so the server sends the neighbour set as JSON rather than as a
       delimiter a path is allowed to contain. */
    function neighboursOf(node) {
        try {
            var parsed = JSON.parse(node.dataset.neighbours || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function clearFocus() {
        selected = null;
        nodes.forEach(function (node) {
            node.classList.remove('graph-node-link--dimmed', 'graph-node-link--selected');
        });
        edges.forEach(function (edge) {
            edge.setAttribute('stroke', 'var(--ink-faint)');
            edge.setAttribute('stroke-opacity', '0.38');
        });
        details.forEach(function (detail) { detail.hidden = true; });
        if (clearButton) { clearButton.hidden = true; }
    }

    function focus(nodeId, moveFocus) {
        var node = nodes.filter(function (candidate) { return candidate.dataset.nodeId === nodeId; })[0];
        if (!node) { return; }
        if (selected === nodeId) { clearFocus(); node.focus(); return; }
        selected = nodeId;
        var keep = neighboursOf(node).concat([nodeId]);
        nodes.forEach(function (candidate) {
            var id = candidate.dataset.nodeId;
            var inFocus = keep.indexOf(id) !== -1;
            candidate.classList.toggle('graph-node-link--dimmed', !inFocus);
            candidate.classList.toggle('graph-node-link--selected', id === nodeId);
        });
        edges.forEach(function (edge) {
            var touches = edge.dataset.source === nodeId || edge.dataset.target === nodeId;
            edge.setAttribute('stroke', touches ? 'var(--accent)' : 'var(--ink-faint)');
            edge.setAttribute('stroke-opacity', touches ? '0.9' : '0.08');
        });
        var panel = null;
        details.forEach(function (detail) {
            detail.hidden = detail.dataset.graphDetail !== nodeId;
            if (!detail.hidden) { panel = detail; }
        });
        if (clearButton) { clearButton.hidden = false; }

        /* Selecting with the keyboard cancels the link's navigation, so without
           this the panel appears somewhere below and nothing announces it.
           Moving focus there is the perceivable result of the activation. */
        if (panel && moveFocus) { panel.focus(); }
    }

    toolbar.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) { return; }
        var action = target.getAttribute('data-graph-zoom');
        if (action === 'fit') { view = base.slice(); applyView(); return; }
        if (action === 'in' || action === 'out') {
            zoom(action === 'in' ? 0.75 : 1 / 0.75, view[0] + view[2] / 2, view[1] + view[3] / 2);
            return;
        }
        if (target.hasAttribute('data-graph-clear')) { clearFocus(); }
    });

    explorer.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) { return; }
        var jump = target.closest('[data-graph-select]');
        if (jump) {
            event.preventDefault();
            focus(jump.getAttribute('data-graph-select'), true);
            return;
        }
        var node = target.closest('.graph-node-link');
        if (node && node.dataset.nodeId) {
            /* The link stays in the markup so the no-JS page still navigates;
               with the explorer running the detail panel offers the same
               destination plus its evidence. */
            event.preventDefault();
            focus(node.dataset.nodeId, true);
        }
    });

    svg.addEventListener('wheel', function (event) {
        event.preventDefault();
        var point = viewPoint(event);
        zoom(event.deltaY < 0 ? 0.88 : 1 / 0.88, point.x, point.y);
    }, { passive: false });

    /* Pan tracking lives on the document while a drag is in flight: pointer
       capture on an SVG root loses moves to its own descendants. */
    var panning = null;

    function stopPanning() {
        if (!panning) { return; }
        panning = null;
        svg.classList.remove('graph-explorer__svg--panning');
        document.removeEventListener('pointermove', onPanMove);
        document.removeEventListener('pointerup', stopPanning);
        document.removeEventListener('pointercancel', stopPanning);
    }

    function onPanMove(event) {
        if (!panning) { return; }
        var rect = svg.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) { return; }
        view[0] = panning.view[0] - ((event.clientX - panning.x) / rect.width) * view[2];
        view[1] = panning.view[1] - ((event.clientY - panning.y) / rect.height) * view[3];
        applyView();
    }

    svg.addEventListener('pointerdown', function (event) {
        if (event.target instanceof Element && event.target.closest('.graph-node-link')) { return; }
        event.preventDefault();
        panning = { x: event.clientX, y: event.clientY, view: view.slice() };
        svg.classList.add('graph-explorer__svg--panning');
        document.addEventListener('pointermove', onPanMove);
        document.addEventListener('pointerup', stopPanning);
        document.addEventListener('pointercancel', stopPanning);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && selected !== null) { clearFocus(); }
    });
}());

/* Interactive workflow graph powered by Cytoscape.js.
 *
 * Progressive enhancement over the server-rendered step list: this renders an
 * interactive node graph showing the sequential workflow stages, color-coded
 * by Loop-owned status (done, current, blocked, pending, not-applicable).
 * Clicking any node reveals its details in the inspector and highlights the
 * corresponding item in the step list. */
(function () {
    var container = document.querySelector('[data-workflow-graph]');
    if (!container) { return; }
    if (typeof window.cytoscape !== 'function') { container.hidden = true; return; }

    var canvas = container.querySelector('[data-workflow-canvas]');
    var tools = container.querySelector('[data-workflow-tools]');
    var inspector = container.querySelector('[data-workflow-inspector]');
    if (!canvas) { return; }

    var rawElements = canvas.getAttribute('data-workflow-elements');
    if (!rawElements) { return; }

    var elements;
    try {
        elements = JSON.parse(rawElements);
    } catch (e) {
        return;
    }

    if (!Array.isArray(elements)) { return; }

    if (tools && elements.length > 0) { tools.hidden = false; }

    /* Canvas styles cannot read CSS custom properties, so resolve the design
       tokens once. Hard-coded hex values here drifted from the stylesheet the
       moment the palette changed. */
    var rootStyle = window.getComputedStyle(document.documentElement);
    function token(name, fallback) {
        var value = rootStyle.getPropertyValue(name).trim();
        return value === '' ? fallback : value;
    }
    var palette = {
        surface: token('--surface-alt', '#f3f5fb'),
        sunk: token('--bg-sunk', '#eef0f7'),
        rule: token('--rule', '#e2e6f0'),
        ruleStrong: token('--rule-strong', '#cdd3e1'),
        ink: token('--ink', '#0b0f19'),
        inkSoft: token('--ink-soft', '#4a5268'),
        inkFaint: token('--ink-faint', '#7c8499'),
        accent: token('--accent', '#2563eb'),
        accentSoft: token('--accent-soft', '#eaf0fe'),
        attention: token('--attention', '#8a5a12'),
        attentionSoft: token('--attention-soft', '#fbf2e2'),
        blocked: token('--blocked', '#b42318'),
        blockedSoft: token('--blocked-soft', '#fdeceb'),
        sans: token('--sans', 'ui-sans-serif, system-ui, sans-serif')
    };

    var cy = window.cytoscape({
        container: canvas,
        elements: elements,
        boxSelectionEnabled: false,
        autounselectify: false,
        style: [
            {
                selector: 'node',
                style: {
                    'shape': 'round-rectangle',
                    'width': 150,
                    'height': 52,
                    'label': 'data(label)',
                    'text-valign': 'center',
                    'text-halign': 'center',
                    'text-wrap': 'wrap',
                    'text-max-width': 130,
                    'font-family': palette.sans,
                    'font-size': 11,
                    'font-weight': 600,
                    'border-width': 2,
                    'border-radius': 6,
                    'background-color': palette.surface,
                    'border-color': palette.ruleStrong,
                    'color': palette.inkSoft
                }
            },
            {
                selector: 'node[status = "done"]',
                style: {
                    'background-color': palette.accentSoft,
                    'border-color': palette.accent,
                    'color': palette.accent
                }
            },
            {
                selector: 'node[status = "current"]',
                style: {
                    'background-color': palette.attentionSoft,
                    'border-color': palette.attention,
                    'color': palette.attention,
                    'border-width': 3
                }
            },
            {
                selector: 'node[status = "blocked"]',
                style: {
                    'background-color': palette.blockedSoft,
                    'border-color': palette.blocked,
                    'color': palette.blocked
                }
            },
            {
                selector: 'node[status = "not_applicable"]',
                style: {
                    'background-color': palette.sunk,
                    'border-color': palette.rule,
                    'border-style': 'dashed',
                    'color': palette.inkFaint
                }
            },
            {
                selector: 'node:selected',
                style: {
                    'border-width': 4,
                    'border-color': palette.ink
                }
            },
            {
                selector: 'edge',
                style: {
                    'curve-style': 'bezier',
                    'target-arrow-shape': 'triangle',
                    'arrow-scale': 1.1,
                    'width': 2,
                    'line-color': palette.ruleStrong,
                    'target-arrow-color': palette.ruleStrong
                }
            },
            {
                selector: 'edge[status = "done"]',
                style: {
                    'width': 3,
                    'line-color': palette.accent,
                    'target-arrow-color': palette.accent
                }
            }
        ],
        layout: {
            name: 'preset',
            fit: true,
            padding: 30
        }
    });

    function showInspector(node) {
        if (!inspector) { return; }
        var data = node.data();
        var indexEl = inspector.querySelector('[data-wf-inspector-index]');
        var labelEl = inspector.querySelector('[data-wf-inspector-label]');
        var pillEl = inspector.querySelector('[data-wf-inspector-pill]');
        var ownerEl = inspector.querySelector('[data-wf-inspector-owner]');
        var reasonEl = inspector.querySelector('[data-wf-inspector-reason]');

        if (indexEl) { indexEl.textContent = data.index || ''; }
        if (labelEl) { labelEl.textContent = data.label || ''; }
        if (pillEl) {
            pillEl.textContent = data.status || '';
            var tone = 'neutral';
            if (data.status === 'done') { tone = 'ok'; }
            else if (data.status === 'current') { tone = 'attention'; }
            else if (data.status === 'blocked') { tone = 'blocked'; }
            pillEl.className = 'pill pill--' + tone;
        }
        if (ownerEl) { ownerEl.textContent = data.owner ? 'owner: ' + data.owner : ''; }
        if (reasonEl) {
            if (data.reason && data.reason.trim() !== '') {
                reasonEl.textContent = data.reason;
                reasonEl.hidden = false;
            } else {
                reasonEl.hidden = true;
            }
        }
        inspector.hidden = false;

        var stepPanelId = 'step-panel-' + (Number(data.index) - 1);
        var stepItem = document.getElementById(stepPanelId);
        if (stepItem) {
            document.querySelectorAll('[id^="step-panel-"]').forEach(function (panel) {
                panel.style.outline = 'none';
            });
            stepItem.style.outline = '2px solid var(--accent)';
            stepItem.style.outlineOffset = '2px';
        }
    }

    /* Keep surviving nodes in place. A refresh updates owner data and edges,
       without running a layout or selecting a different node for the reader. */
    var nextLiveNode = 0;
    document.addEventListener('agent-ui:workflow-progress', function (event) {
        var steps = event.detail && event.detail.steps;
        if (!Array.isArray(steps)) { return; }

        var hadNodes = cy.nodes().length > 0;
        var existing = Object.create(null);
        var kept = Object.create(null);
        var orderedNodeIds = [];
        cy.nodes().forEach(function (node) { existing[node.data('step_id')] = node; });

        container.hidden = steps.length === 0;
        if (tools) { tools.hidden = steps.length === 0; }
        cy.batch(function () {
            steps.forEach(function (step, index) {
                var node = existing[step.id];
                var data = {
                    step_id: step.id,
                    index: index + 1,
                    label: (index + 1) + '. ' + step.label,
                    status: step.status,
                    owner: step.owner,
                    reason: step.reason || ''
                };
                if (node) {
                    node.data(data);
                } else {
                    data.id = 'live_step_' + nextLiveNode++;
                    node = cy.add({ group: 'nodes', data: data, position: { x: 100 + index * 190, y: 110 } });
                }
                kept[step.id] = true;
                orderedNodeIds.push(node.id());
            });
            Object.keys(existing).forEach(function (id) {
                if (!kept[id]) { existing[id].remove(); }
            });
            cy.edges().remove();
            for (var index = 1; index < orderedNodeIds.length; index++) {
                cy.add({
                    group: 'edges',
                    data: {
                        id: 'live_edge_' + index,
                        source: orderedNodeIds[index - 1],
                        target: orderedNodeIds[index],
                        status: steps[index - 1].status === 'done' ? 'done' : 'pending'
                    }
                });
            }
        });

        if (steps.length === 0) {
            if (inspector) { inspector.hidden = true; }
            return;
        }
        if (!hadNodes) {
            cy.resize();
            var firstCurrent = cy.nodes('[status = "current"]');
            if (firstCurrent.length === 0) { firstCurrent = cy.nodes('[status = "blocked"]'); }
            if (firstCurrent.length > 0) { firstCurrent.select(); }
            if (cy.width() < 600) {
                cy.zoom(0.8);
                cy.center(firstCurrent.length > 0 ? firstCurrent : cy.nodes().first());
            } else {
                cy.fit(null, 30);
            }
        }
        var selected = cy.nodes(':selected');
        if (selected.length > 0) { showInspector(selected[0]); }
        else if (inspector) { inspector.hidden = true; }
    });

    var currentNode = cy.nodes('[status = "current"]');
    if (currentNode.length === 0) {
        currentNode = cy.nodes('[status = "blocked"]');
    }
    if (currentNode.length > 0) {
        currentNode.select();
        showInspector(currentNode[0]);
    }
    if (cy.width() < 600 && cy.nodes().length > 0) {
        cy.zoom(0.8);
        cy.center(currentNode.length > 0 ? currentNode : cy.nodes().first());
    }

    cy.on('tap', 'node', function (evt) {
        showInspector(evt.target);
    });

    if (tools) {
        tools.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) { return; }
            var zoomAction = target.getAttribute('data-wf-zoom');
            if (zoomAction === 'in') {
                cy.zoom({ level: cy.zoom() * 1.25, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } });
                return;
            }
            if (zoomAction === 'out') {
                cy.zoom({ level: cy.zoom() * 0.8, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } });
                return;
            }
            if (zoomAction === 'fit') {
                cy.fit(null, 30);
                return;
            }
            if (target.hasAttribute('data-wf-reset')) {
                cy.elements().layout({ name: 'preset', fit: true, padding: 30 }).run();
            }
        });
    }

    window.addEventListener('resize', function () {
        cy.resize();
    });
}());

/* Refresh the Loop projection without navigating away from the current graph.
 * A failed refresh leaves the last rendered state visible and labels it stale. */
(function () {
    var live = document.querySelector('[data-workflow-live]');
    if (!live) { return; }
    var url = live.getAttribute('data-progress-url');
    var taskId = live.getAttribute('data-task-id');
    var status = live.querySelector('[data-workflow-live-status]');
    var retry = live.querySelector('[data-workflow-live-retry]');
    var decision = document.querySelector('[data-workflow-decision]');
    var empty = document.querySelector('[data-progress-empty]');
    var details = document.querySelector('[data-progress-details]');
    var list = document.querySelector('[data-progress-list]');
    if (!url || !taskId || !status || !retry || !decision || !empty || !details || !list) { return; }

    status.hidden = false;
    status.textContent = 'Connecting to live workflow updates…';
    if (typeof window.fetch !== 'function') {
        status.textContent = 'Live updates are unavailable in this browser. Reload for the current workflow.';
        return;
    }

    var timer = null;
    var inFlight = false;
    var failures = 0;
    var currentState = '';
    var lastSteps = null;

    function text(selector, value) {
        var element = document.querySelector(selector);
        if (element) { element.textContent = value; }
    }

    function pill(selector, label, tone) {
        var element = document.querySelector(selector);
        if (!element) { return; }
        if (['ok', 'attention', 'blocked', 'neutral'].indexOf(tone) === -1) { tone = 'neutral'; }
        element.textContent = label;
        element.className = 'pill pill--' + tone;
    }

    function validSnapshot(value) {
        if (!value || value.schema_version !== 1 || value.task_id !== taskId ||
            !Array.isArray(value.steps) || value.steps.length > 100 ||
            typeof value.has_human_decision !== 'boolean' ||
            typeof value.decision_html !== 'string' ||
            typeof value.decision_digest !== 'string' || !/^[a-f0-9]{64}$/.test(value.decision_digest) ||
            (value.command_reference !== null && typeof value.command_reference !== 'string')) { return false; }
        var fields = ['state', 'state_label', 'state_tone', 'next_action', 'next_action_kind',
            'next_action_kind_label', 'next_action_kind_tone', 'next_action_hint'];
        if (!fields.every(function (field) { return typeof value[field] === 'string'; })) { return false; }
        var seen = Object.create(null);
        return value.steps.every(function (step) {
            if (!step || typeof step.id !== 'string' || step.id === '' || seen[step.id] ||
                typeof step.label !== 'string' || typeof step.status !== 'string' ||
                typeof step.status_label !== 'string' || typeof step.owner !== 'string' ||
                (step.reason !== null && typeof step.reason !== 'string')) { return false; }
            seen[step.id] = true;
            return true;
        });
    }

    function renderSteps(steps) {
        empty.hidden = steps.length !== 0;
        details.hidden = steps.length === 0;
        var fragment = document.createDocumentFragment();
        steps.forEach(function (step, index) {
            var item = document.createElement('li');
            item.id = 'step-panel-' + index;
            item.className = 'panel' + (step.status === 'blocked' ? ' panel--danger' :
                (step.status === 'current' ? ' panel--attention' : ''));
            item.style.margin = '0';
            var head = document.createElement('div');
            head.className = 'action__head';
            var left = document.createElement('div');
            left.style.cssText = 'display:flex;gap:10px;align-items:center;min-width:0';
            var number = document.createElement('span');
            number.className = 'mono small faint';
            number.textContent = String(index + 1);
            var label = document.createElement('strong');
            label.textContent = step.label;
            var state = document.createElement('span');
            var tone = step.status === 'done' ? 'ok' : (step.status === 'current' ? 'attention' :
                (step.status === 'blocked' ? 'blocked' : 'neutral'));
            state.className = 'pill pill--' + tone;
            state.textContent = step.status_label;
            left.append(number, label, state);
            var owner = document.createElement('span');
            owner.className = 'small faint';
            owner.textContent = 'owner: ' + step.owner;
            head.append(left, owner);
            item.appendChild(head);
            if (step.reason !== null) {
                var reason = document.createElement('p');
                reason.className = 'note';
                reason.style.marginTop = '8px';
                reason.textContent = step.reason;
                item.appendChild(reason);
            }
            fragment.appendChild(item);
        });
        list.replaceChildren(fragment);
    }

    function applySnapshot(snapshot) {
        pill('[data-progress-state]', snapshot.state_label, snapshot.state_tone);
        pill('[data-task-workflow-state]', snapshot.state_label, snapshot.state_tone);
        pill('[data-task-next-kind]', snapshot.next_action_kind_label, snapshot.next_action_kind_tone);
        text('[data-task-next-hint]', snapshot.next_action_hint);
        text('[data-task-next-action]', snapshot.next_action);

        var decisionSlot = document.querySelector('[data-task-decision-slot]');
        if (decisionSlot) {
            var decisionLink = decisionSlot.querySelector('[data-task-decision-link]');
            if (snapshot.has_human_decision && !decisionLink) {
                decisionLink = document.createElement('p');
                decisionLink.className = 'task-context__decision';
                decisionLink.setAttribute('data-task-decision-link', '');
                var decisionAnchor = document.createElement('a');
                decisionAnchor.className = 'btn btn--primary';
                decisionAnchor.setAttribute('href', '/task/' + encodeURIComponent(taskId));
                decisionAnchor.hash = 'decision';
                decisionAnchor.textContent = 'Go to the decision agent-loop is waiting for';
                decisionLink.appendChild(decisionAnchor);
                decisionSlot.appendChild(decisionLink);
            } else if (!snapshot.has_human_decision && decisionLink) {
                decisionLink.remove();
            }
        }
        var reference = document.querySelector('[data-task-command-reference]');
        var referenceLink = document.querySelector('[data-task-command-link]');
        if (reference) { reference.hidden = snapshot.command_reference === null; }
        if (referenceLink && snapshot.command_reference !== null) {
            referenceLink.setAttribute('href', '/commands#' + encodeURIComponent(snapshot.command_reference));
            text('[data-task-command-name]', snapshot.command_reference);
        }

        if (decision.getAttribute('data-decision-digest') !== snapshot.decision_digest) {
            // This fragment is rendered and escaped by the same server template as the initial page.
            decision.innerHTML = snapshot.decision_html;
            decision.setAttribute('data-decision-digest', snapshot.decision_digest);
        }

        var serializedSteps = JSON.stringify(snapshot.steps);
        if (serializedSteps !== lastSteps) {
            renderSteps(snapshot.steps);
            document.dispatchEvent(new CustomEvent('agent-ui:workflow-progress', { detail: { steps: snapshot.steps } }));
            lastSteps = serializedSteps;
        }
        currentState = snapshot.state;
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        timer = window.setTimeout(poll, delay);
    }

    function poll() {
        if (inFlight) { return; }
        inFlight = true;
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var deadline = controller ? window.setTimeout(function () { controller.abort(); }, 10000) : null;
        var options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
        if (controller) { options.signal = controller.signal; }
        window.fetch(url, options).then(function (response) {
            if (!response.ok) { throw new Error('Workflow refresh failed.'); }
            return response.json();
        }).then(function (snapshot) {
            if (!validSnapshot(snapshot)) { throw new Error('Invalid workflow projection.'); }
            applySnapshot(snapshot);
            failures = 0;
            status.textContent = 'Live workflow updates connected.';
            retry.hidden = true;
        }).catch(function () {
            failures++;
            status.textContent = 'Live updates unavailable. Showing the last loaded workflow state.';
            retry.hidden = false;
        }).then(function () {
            if (deadline !== null) { window.clearTimeout(deadline); }
            inFlight = false;
            var delay = document.hidden || currentState === 'complete'
                ? 30000
                : (failures > 0 ? Math.min(30000, 4000 * failures) : 4000);
            schedule(delay);
        });
    }

    retry.addEventListener('click', function () {
        window.clearTimeout(timer);
        poll();
    });
    document.addEventListener('visibilitychange', function () {
        window.clearTimeout(timer);
        if (document.hidden) { if (!inFlight) { schedule(30000); } }
        else { poll(); }
    });
    poll();
}());

/* Local process observation and explicit session controls stay separate from Loop state. */
(function enhanceLocalTerminal() {
    var panel = document.querySelector('[data-terminal-panel]');
    if (!panel) { return; }
    var frame = panel.querySelector('[data-terminal-frame]');
    var toggle = panel.querySelector('[data-terminal-toggle]');
    var stop = panel.querySelector('[data-terminal-stop]');
    var start = panel.querySelector('[data-terminal-start]');
    var startButton = panel.querySelector('[data-terminal-start-button]');
    var host = panel.querySelector('[data-terminal-host]');
    var message = panel.querySelector('[data-terminal-message]');
    var state = panel.querySelector('[data-terminal-state]');
    var attachment = panel.querySelector('[data-terminal-attachment]');
    var imageFile = panel.querySelector('[data-terminal-image-file]');
    var pasteClipboard = panel.querySelector('[data-terminal-image-clipboard]');
    if (!frame || !toggle || !stop || !start || !startButton || !host || !message || !state ||
        !attachment || !imageFile || !pasteClipboard) { return; }
    var terminalUrl = frame.getAttribute('data-terminal-url');
    var connected = true;
    var viewerWanted = true;
    var csrf = '';
    var timer;
    var busy = false;
    var generation = 0;

    function setViewer(wanted) {
        viewerWanted = wanted;
        if (connected !== wanted) { frame.src = wanted ? terminalUrl : 'about:blank'; }
        connected = wanted;
        toggle.textContent = wanted ? 'Disconnect' : 'Reconnect';
        message.textContent = wanted
            ? 'Connection status appears in the terminal. Disconnecting leaves the session running.'
            : 'Viewer disconnected. The tmux session was not stopped.';
    }
    toggle.addEventListener('click', function () { setViewer(!viewerWanted); });
    if (typeof window.fetch !== 'function') { return; }
    state.textContent = 'Checking local session…';
    function refreshSession() {
        window.clearTimeout(timer);
        var requestGeneration = ++generation;
        window.fetch('/_local/session', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { if (!response.ok) { throw new Error('Unavailable'); } return response.json(); })
            .then(function (snapshot) {
                if (requestGeneration !== generation) { return; }
                csrf = snapshot.csrf;
                var running = snapshot.state === 'session_running';
                var missing = snapshot.state === 'session_not_found';
                var available = snapshot.terminal_available === true;
                var cleanup = missing && snapshot.attachments && snapshot.attachments.state === 'available';
                state.textContent = running ? 'tmux session running (' + snapshot.host + ')'
                    : missing ? 'tmux session not found' : 'Local session status unavailable';
                if (!available) { state.textContent += ' · terminal transport unavailable'; }
                if (!snapshot.attachments || snapshot.attachments.state === 'unavailable') { state.textContent += ' · task images unavailable'; }
                toggle.hidden = !running || !available;
                stop.hidden = !running && !cleanup;
                stop.textContent = cleanup ? 'Clean up task images' : 'Stop session';
                stop.setAttribute('data-cleanup-only', cleanup ? 'true' : 'false');
                start.hidden = !missing;
                frame.hidden = !running || !available;
                attachment.hidden = !running || !available;
                imageFile.disabled = busy || !running || !available;
                pasteClipboard.disabled = busy || !running || !available ||
                    !navigator.clipboard || typeof navigator.clipboard.read !== 'function';
                if (!running || !available) {
                    if (connected) { frame.src = 'about:blank'; connected = false; }
                } else if (viewerWanted && !connected) {
                    setViewer(true);
                }
                Array.prototype.forEach.call(host.options, function (option) {
                    option.disabled = !snapshot.hosts || snapshot.hosts[option.value] !== true;
                });
                if (host.selectedOptions.length && host.selectedOptions[0].disabled) {
                    var first = Array.prototype.find.call(host.options, function (option) { return !option.disabled; });
                    if (first) { host.value = first.value; }
                }
                startButton.disabled = busy || !available || !host.selectedOptions.length || host.selectedOptions[0].disabled;
                stop.disabled = busy;
            })
            .catch(function () {
                if (requestGeneration === generation) { state.textContent = 'Local session status unavailable'; start.hidden = true; stop.hidden = true; }
            })
            .then(function () { if (requestGeneration === generation) { timer = window.setTimeout(refreshSession, document.hidden ? 30000 : 5000); } });
    }
    function action(name, payload) {
        if (busy || !csrf) { return; }
        busy = true;
        generation++;
        window.clearTimeout(timer);
        startButton.disabled = true;
        stop.disabled = true;
        message.textContent = name === 'start' ? 'Starting the selected coding host…' : 'Stopping the local session…';
        window.fetch('/_local/session/' + name, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-Agent-Ui-Csrf': csrf },
            body: JSON.stringify(payload)
        }).then(function (response) {
            if (!response.ok) { return response.text().then(function (detail) { throw new Error(detail.slice(0, 200)); }); }
            return response.json();
        }).then(function (result) {
            if (name === 'start') { viewerWanted = true; }
            message.textContent = name === 'start' ? 'Session started.'
                : result.stopped ? 'Session stopped. The governed task was not closed.'
                : 'The session had already ended; its task images were removed.';
        }).catch(function (error) {
            message.textContent = error.message || 'The local session action failed.';
        }).then(function () { busy = false; refreshSession(); });
    }
    function uploadImage(blob) {
        if (busy || !csrf) { return; }
        if (!blob || !['image/png', 'image/jpeg', 'image/webp', 'image/gif'].includes(blob.type)) {
            message.textContent = 'Choose a PNG, JPEG, WebP or GIF image.';
            return;
        }
        if (blob.size > 8 * 1024 * 1024) {
            message.textContent = 'Image exceeds 8 MiB.';
            return;
        }
        busy = true;
        imageFile.disabled = true;
        pasteClipboard.disabled = true;
        message.textContent = 'Uploading image to the local task session…';
        window.fetch('/_local/attachment', {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': blob.type, 'X-Agent-Ui-Csrf': csrf },
            body: blob
        }).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (detail) { throw new Error(detail.slice(0, 200)); });
            }
            return response.json();
        }).then(function (result) {
            message.textContent = 'Image path pasted (not submitted): ' + result.path;
        }).catch(function (error) {
            message.textContent = error.message || 'Image upload failed.';
        }).then(function () {
            busy = false;
            refreshSession();
        });
    }
    imageFile.addEventListener('change', function () {
        if (imageFile.files && imageFile.files.length) { uploadImage(imageFile.files[0]); }
        imageFile.value = '';
    });
    pasteClipboard.addEventListener('click', function () {
        if (!navigator.clipboard || typeof navigator.clipboard.read !== 'function') {
            message.textContent = 'Clipboard images are unavailable; choose a file instead.';
            return;
        }
        navigator.clipboard.read().then(function (items) {
            var types = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
            for (var i = 0; i < items.length; i++) {
                for (var j = 0; j < types.length; j++) {
                    if (items[i].types.indexOf(types[j]) !== -1) { return items[i].getType(types[j]); }
                }
            }
            throw new Error('Clipboard contains no supported image.');
        }).then(uploadImage).catch(function (error) {
            message.textContent = error.message || 'Clipboard access denied; choose a file instead.';
        });
    });
    attachment.addEventListener('dragover', function (event) {
        event.preventDefault();
    });
    attachment.addEventListener('drop', function (event) {
        event.preventDefault();
        if (event.dataTransfer && event.dataTransfer.files.length) {
            uploadImage(event.dataTransfer.files[0]);
        }
    });
    startButton.addEventListener('click', function () { action('start', { host: host.value }); });
    stop.addEventListener('click', function () {
        var prompt = stop.getAttribute('data-cleanup-only') === 'true'
            ? 'Remove the remaining task images?'
            : 'Stop this coding-host session? This can interrupt its work and does not close the task.';
        if (window.confirm(prompt)) { action('stop', {}); }
    });
    refreshSession();
}());

/*
 * Optional shortcut: "/" moves focus to the header search, as on most developer
 * tools. The form is a plain GET form and works without this; the hint glyph
 * ships hidden and is revealed here, so the page never advertises a key it
 * cannot deliver. Typing a "/" into any field - including the search box itself
 * - is left alone.
 */
(function enhanceSearchShortcut() {
    var input = document.getElementById('global-search');
    if (!input) { return; }
    var hint = document.querySelector('.masthead-search__hint');
    if (hint) { hint.hidden = false; }
    document.addEventListener('keydown', function (event) {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.defaultPrevented) { return; }
        var target = event.target;
        var tag = target && target.tagName ? target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (target && target.isContentEditable)) { return; }
        event.preventDefault();
        input.focus();
        input.select();
    });
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { input.blur(); }
    });
})();
