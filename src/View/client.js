/* Progressive enhancement only: every command stays visible and selectable without it. */
document.querySelectorAll('.copy').forEach(function (button) {
    if (!navigator.clipboard) { return; }
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
