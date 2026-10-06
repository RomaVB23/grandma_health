// Refresh only an idle history page. A filter being edited or an open event
// stays in place until the user explicitly refreshes or closes the details.
(() => {
    const toggle = document.getElementById('auto-refresh');
    if (!toggle) return;
    let edited = false;
    try { toggle.checked = sessionStorage.getItem('grandma-history-refresh') === 'on'; } catch (_) {}
    toggle.addEventListener('change', () => {
        try { sessionStorage.setItem('grandma-history-refresh', toggle.checked ? 'on' : 'off'); } catch (_) {}
    });
    document.querySelector('.filters')?.addEventListener('input', () => { edited = true; });
    setInterval(() => {
        if (toggle.checked && !edited && !document.hidden
            && !document.querySelector('details[open]') && !document.activeElement?.matches('.filters input, .filters select')) {
            window.location.reload();
        }
    }, 60_000);
})();
