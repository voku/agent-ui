/* Progressive enhancement only: every command stays visible and selectable without it. */
document.querySelectorAll('.copy').forEach(function (button) {
    if (!navigator.clipboard) { return; }
    button.hidden = false;
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
