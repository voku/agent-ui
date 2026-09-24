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
    if (!container || typeof window.cytoscape !== 'function') { return; }

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

    if (!Array.isArray(elements) || elements.length === 0) { return; }

    if (tools) { tools.hidden = false; }

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

    var currentNode = cy.nodes('[status = "current"]');
    if (currentNode.length === 0) {
        currentNode = cy.nodes('[status = "blocked"]');
    }
    if (currentNode.length > 0) {
        currentNode.select();
        showInspector(currentNode[0]);
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
        cy.fit(null, 30);
    });
}());
