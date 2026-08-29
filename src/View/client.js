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
