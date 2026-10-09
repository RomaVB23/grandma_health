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
    document.querySelectorAll('.filters, .chart-controls').forEach(form =>
        form.addEventListener('input', () => { edited = true; }));
    setInterval(() => {
        if (toggle.checked && !window.grandmaMeasurementPending && !edited && !document.hidden
            && !document.querySelector('details[open]') && !document.activeElement?.matches('.filters input, .filters select')) {
            window.location.reload();
        }
    }, 10_000);
})();

// This button starts a command; ordinary refresh never turns the sensor on.
(() => {
    const root = document.getElementById('remote-measurement');
    if (!root) return;
    const form = document.getElementById('measurement-form');
    const button = document.getElementById('measurement-button');
    const output = document.getElementById('measurement-result');
    let id = root.dataset.requestId || '', pending = Boolean(id), polling = false;
    let nextAllowed = 0;
    function render() {
        window.grandmaMeasurementPending = pending;
        button.disabled = pending || Date.now() < nextAllowed;
        button.textContent = pending ? 'Ожидаем новый замер…' : Date.now() < nextAllowed ? 'Подождите 10 секунд' : '❤️ Измерить сейчас';
    }
    async function json(url, options = {}) {
        const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store',
            ...options, headers: {'Accept': 'application/json', ...options.headers}, signal: AbortSignal.timeout(10_000)});
        if (response.redirected || response.status === 401) throw new Error('Сеанс завершён. Обновите страницу и войдите снова.');
        const value = await response.json();
        if (!response.ok) throw new Error(Object.values(value.errors || {}).flat()[0] || 'Не удалось получить ответ сервера.');
        return value;
    }
    function show(result) {
        id = result.id; pending = result.pending;
        output.textContent = result.message + (result.status === 'success' ? '\nЖурнал обновится после передачи пакета с телефона; нажмите «Обновить» для просмотра строки.' : '');
        output.classList.toggle('status-text', !pending);
        output.classList.toggle('green', result.status === 'success');
        if (result.status === 'success') {
            // Show the correlated result immediately; the journal still uses normal telemetry uploads.
            const pulse = document.getElementById('latest-pulse');
            pulse.textContent = result.bpm + ' ';
            const unit = document.createElement('small'); unit.textContent = 'уд/мин'; pulse.append(unit);
            document.getElementById('latest-pulse-time').textContent = 'Измерен ' +
                new Date(result.measured_at_ms).toLocaleString('ru-RU', {timeZone: root.dataset.timezone});
            const age = pulse.parentElement.querySelector('.pill');
            age.textContent = 'Новый замер по запросу'; age.className = 'pill neutral';
            setTimeout(() => document.dispatchEvent(new Event('grandma-measurement-completed')), 3000);
        }
        render();
    }
    async function poll() {
        if (!pending || polling) return;
        polling = true;
        try { show(await json(root.dataset.statusBase + '/' + encodeURIComponent(id))); }
        catch (error) {
            output.textContent = error.message + ' Результат запроса пока неизвестен; проверим снова.';
            // Do not pretend an interrupted network connection produced a failed sensor result.
        } finally { polling = false; }
    }
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending || Date.now() < nextAllowed) return;
        pending = true; render(); output.textContent = 'Создаём запрос нового замера…';
        try {
            const result = await json(root.dataset.endpoint, {method: 'POST',
                headers: {'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value}});
            nextAllowed = Date.now() + 10_000; show(result); await poll();
        } catch (error) {
            pending = false; id = ''; render();
            output.textContent = error.message + ' Обновите страницу, чтобы проверить последний запрос.';
        }
    });
    setInterval(() => { render(); poll(); }, 2000);
    render(); if (pending) poll();
})();
