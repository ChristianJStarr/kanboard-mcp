(function () {
    var box = document.getElementById('mcp-disable-url-token');
    var form = document.getElementById('mcp-url-token-form');

    if (!box || box.disabled || !form) {
        return;
    }

    box.addEventListener('change', function () {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        form.submit();
    });
}());
