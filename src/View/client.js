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
