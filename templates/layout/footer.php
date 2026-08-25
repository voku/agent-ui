</main>
<div class="wrap" style="padding-top:0">
    <p class="footer">
        agent-ui presents and invokes owner capabilities. It owns no workflow, setup, context,
        board, execution, approval, verification, review or Learning truth — every fact on these
        pages is rendered from the package that owns it.
    </p>
</div>
<script>
    /* Progressive enhancement only: every command stays visible and selectable without it. */
    document.querySelectorAll('.copy').forEach(function (button) {
        button.hidden = !navigator.clipboard;
        button.addEventListener('click', function () {
            var source = document.getElementById(button.dataset.copyTarget);
            if (!source) { return; }
            navigator.clipboard.writeText(source.textContent.trim()).then(function () {
                var original = button.textContent;
                button.textContent = 'Copied';
                button.dataset.copied = '1';
                setTimeout(function () {
                    button.textContent = original;
                    delete button.dataset.copied;
                }, 1500);
            });
        });
    });
</script>
</body>
</html>
