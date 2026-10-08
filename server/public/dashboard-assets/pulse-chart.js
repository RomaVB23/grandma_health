// Local SVG chart: no CDN, external requests or aggregation of pulse extremes.
(() => {
    'use strict';
    function seriesModel(data) {
        const segments = [], gaps = [];
        let segment = [], previous = data.from_ms;
        for (const point of data.points) {
            if (point[0] - previous > data.gap_ms) {
                if (segment.length) segments.push(segment);
                segment = [];
                gaps.push([previous, point[0]]);
            }
            segment.push(point);
            previous = point[0];
        }
        if (segment.length) segments.push(segment);
        if (data.to_ms - previous > data.gap_ms) gaps.push([previous, data.to_ms]);
        return {segments, gaps};
    }
    // Exposed for small calculation tests, independent of the DOM.
    globalThis.GrandmaPulseChart = {seriesModel};
    if (typeof document === 'undefined') return;
    const root = document.getElementById('pulse-chart');
    if (!root) return;
    const form = document.getElementById('chart-form');
    const period = document.getElementById('chart-period');
    const from = document.getElementById('chart-from');
    const to = document.getElementById('chart-to');
    const gap = document.getElementById('chart-gap');
    const plot = document.getElementById('chart-plot');
    const svg = document.getElementById('chart-svg');
    const tooltip = document.getElementById('chart-tooltip');
    const message = document.getElementById('chart-message');
    const count = document.getElementById('chart-count');
    const thresholds = document.getElementById('chart-thresholds');
    const NS = 'http://www.w3.org/2000/svg';
    let data = null, selection = -1, controller = null, draft = false, layout = null;
    function element(tag, attributes = {}, text = null) {
        const item = document.createElementNS(NS, tag);
        for (const [key, value] of Object.entries(attributes)) item.setAttribute(key, String(value));
        if (text !== null) item.textContent = text;
        svg.appendChild(item);
        return item;
    }
    function localInput(date, timezone) {
        const parts = new Intl.DateTimeFormat('en-GB', {timeZone: timezone, year: 'numeric', month: '2-digit',
            day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23'}).formatToParts(date);
        const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
        return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}`;
    }
    function fullTime(timestamp) {
        return new Intl.DateTimeFormat('ru-RU', {timeZone: data.timezone, dateStyle: 'short', timeStyle: 'medium'}).format(timestamp);
    }
    function description() {
        if (!data.points.length) return `За выбранный период замеров нет · ${data.timezone}`;
        return `${data.points.length} уникальных замеров · ${fullTime(data.from_ms)} — ${fullTime(data.to_ms)} · ${data.timezone}`;
    }
    function duration(ms) {
        if (ms === null || ms === undefined) return '—';
        let seconds = Math.ceil(ms / 1000);
        const days = Math.floor(seconds / 86400); seconds %= 86400;
        const hours = Math.floor(seconds / 3600); seconds %= 3600;
        const minutes = Math.floor(seconds / 60); seconds %= 60;
        return [days ? `${days} д` : '', hours ? `${hours} ч` : '', minutes ? `${minutes} мин` : '',
            seconds || (!days && !hours && !minutes) ? `${seconds} с` : ''].filter(Boolean).join(' ');
    }
    function renderReport() {
        const report = data.report;
        const text = (id, value) => {document.getElementById(id).textContent = value;};
        const number = value => new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 1}).format(value);
        text('report-period', `${fullTime(data.from_ms)} — ${fullTime(data.to_ms)} · ${data.timezone}`);
        text('report-count', number(report.pulse.count));
        text('report-pulse', report.pulse.count
            ? `Пульс: ${report.pulse.min_bpm}–${report.pulse.max_bpm} · средний ${number(report.pulse.mean_bpm)} уд/мин`
            : 'За выбранный период замеров нет');
        text('report-mean-gap', duration(report.intervals.mean_ms));
        text('report-max-gap', duration(report.intervals.max_ms));
        document.getElementById('report-max-gap').classList.toggle('gap-emphasis', report.intervals.max_ms > data.gap_ms);
        text('report-max-range', report.intervals.max_ms !== null
            ? `${fullTime(report.intervals.max_from_ms)} — ${fullTime(report.intervals.max_to_ms)}`
            : 'Нужно хотя бы два замера');
        text('report-sample-range', report.pulse.count
            ? `${fullTime(report.pulse.first_at_ms)} — ${fullTime(report.pulse.last_at_ms)}` : 'Нет замеров');
        text('report-long-gaps', `${report.intervals.long_gap_count} · больше ${duration(data.gap_ms)}`);
        text('report-edges', report.pulse.count
            ? `${duration(report.edges.before_first_ms)} / ${duration(report.edges.after_last_ms)}`
            : `Весь период без замеров: ${duration(report.edges.before_first_ms)}`);
        const battery = report.battery;
        text('report-battery-change', battery.delta_pp === null ? '—'
            : `${battery.delta_pp > 0 ? '+' : battery.delta_pp < 0 ? '−' : ''}${Math.abs(battery.delta_pp)} п.п.`);
        text('report-battery-summary', battery.snapshot_count > 1
            ? `${battery.first.percent}% → ${battery.last.percent}% · ${number(battery.snapshot_count)} снимков`
            : battery.snapshot_count === 1 ? 'Только один снимок: изменение неизвестно' : 'Нет снимков заряда за период');
        text('report-battery-range', battery.snapshot_count
            ? `${battery.first.percent}% (${fullTime(battery.first.at_ms)}) → ${battery.last.percent}% (${fullTime(battery.last.at_ms)})`
            : 'Нет данных');
        text('report-charging', battery.charging_observed === null ? 'Нет данных'
            : battery.charging_observed ? 'Есть снимки со статусом «Заряжаются»' : 'В сохранённых снимках зарядка не зафиксирована');
    }
    function render() {
        if (!data) return;
        svg.replaceChildren();
        tooltip.hidden = true;
        const width = Math.max(plot.clientWidth, 320), height = 300;
        const left = 46, right = 20, top = 25, bottom = 48;
        const w = width - left - right, h = height - top - bottom;
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('width', '100%'); svg.setAttribute('height', height);
        element('title', {}, 'Уникальные замеры пульса по времени измерения');
        element('desc', {}, description());
        if (!data.points.length) {
            element('text', {x: width / 2, y: height / 2, 'text-anchor': 'middle', class: 'chart-empty'}, 'За этот период замеров нет');
            layout = null;
            return;
        }
        let low = data.thresholds.lower, high = data.thresholds.upper;
        for (const point of data.points) {low = Math.min(low, point[1]); high = Math.max(high, point[1]);}
        const min = Math.max(0, Math.floor((low - 10) / 10) * 10);
        const max = Math.ceil((high + 10) / 10) * 10;
        const x = time => left + (time - data.from_ms) / (data.to_ms - data.from_ms) * w;
        const y = bpm => top + (max - bpm) / (max - min) * h;
        layout = {x, y, left, top, w, h, width};
        const model = seriesModel(data);
        for (const [start, end] of model.gaps) {
            element('rect', {x: x(start), y: top, width: Math.max(0, x(end) - x(start)), height: h, class: 'chart-gap-area'});
        }
        for (let i = 0; i <= 4; i++) {
            const value = min + (max - min) * i / 4;
            element('line', {x1: left, x2: left + w, y1: y(value), y2: y(value), class: 'chart-grid-line'});
            element('text', {x: left - 9, y: y(value) + 4, 'text-anchor': 'end', class: 'chart-axis'}, Math.round(value));
        }
        element('text', {x: left, y: 14, class: 'chart-axis'}, 'уд/мин');
        const formatter = new Intl.DateTimeFormat('ru-RU', {timeZone: data.timezone, hour: '2-digit', minute: '2-digit',
            ...(data.to_ms - data.from_ms > 86_400_000 ? {day: '2-digit', month: '2-digit'} : {})});
        const ticks = width < 500 ? 2 : 4;
        for (let i = 0; i <= ticks; i++) {
            const time = data.from_ms + (data.to_ms - data.from_ms) * i / ticks;
            element('text', {x: x(time), y: top + h + 27,
                'text-anchor': i === 0 ? 'start' : i === ticks ? 'end' : 'middle', class: 'chart-axis'}, formatter.format(time));
        }
        for (const value of [data.thresholds.lower, data.thresholds.upper]) {
            element('line', {x1: left, x2: left + w, y1: y(value), y2: y(value), class: 'chart-threshold-line'});
            element('text', {x: left + w - 3, y: y(value) - 5, 'text-anchor': 'end', class: 'chart-threshold-label'}, value);
        }
        for (const segment of model.segments) {
            if (segment.length === 1) {
                element('circle', {cx: x(segment[0][0]), cy: y(segment[0][1]), r: 3, class: 'chart-point'});
            } else {
                const path = segment.map((point, index) => `${index ? 'L' : 'M'}${x(point[0]).toFixed(2)},${y(point[1]).toFixed(2)}`).join(' ');
                element('path', {d: path, class: 'chart-pulse-line'});
            }
        }
        element('line', {id: 'chart-cursor', y1: top, y2: top + h, class: 'chart-cursor', visibility: 'hidden'});
        element('circle', {id: 'chart-selection', r: 5, class: 'chart-selection', visibility: 'hidden'});
        if (selection >= 0 && selection < data.points.length) select(selection, false);
    }
    function select(index, announce = true) {
        if (!layout || !data.points.length) return;
        selection = Math.max(0, Math.min(data.points.length - 1, index));
        const point = data.points[selection], px = layout.x(point[0]), py = layout.y(point[1]);
        const marker = document.getElementById('chart-selection'), cursor = document.getElementById('chart-cursor');
        marker.setAttribute('cx', px); marker.setAttribute('cy', py); marker.setAttribute('visibility', 'visible');
        cursor.setAttribute('x1', px); cursor.setAttribute('x2', px); cursor.setAttribute('visibility', 'visible');
        tooltip.textContent = `${point[1]} уд/мин · ${fullTime(point[0])}`;
        tooltip.hidden = false;
        tooltip.style.left = `${Math.max(8, Math.min(px - 110, layout.width - 244))}px`;
        tooltip.style.top = `${Math.max(6, py - 52)}px`;
        if (announce) message.textContent = `Замер ${selection + 1} из ${data.points.length}: ${tooltip.textContent}`;
    }
    plot.addEventListener('pointermove', event => {
        if (!layout) return;
        const rect = plot.getBoundingClientRect();
        const time = data.from_ms + ((event.clientX - rect.left) - layout.left) / layout.w * (data.to_ms - data.from_ms);
        let lo = 0, hi = data.points.length - 1;
        while (lo < hi) {const mid = (lo + hi) >> 1; if (data.points[mid][0] < time) lo = mid + 1; else hi = mid;}
        if (lo > 0 && Math.abs(data.points[lo - 1][0] - time) < Math.abs(data.points[lo][0] - time)) lo--;
        select(lo, false);
    });
    plot.addEventListener('pointerleave', () => {
        if (document.activeElement === plot) return;
        tooltip.hidden = true;
        document.getElementById('chart-selection')?.setAttribute('visibility', 'hidden');
        document.getElementById('chart-cursor')?.setAttribute('visibility', 'hidden');
    });
    plot.addEventListener('keydown', event => {
        if (!data?.points.length || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? data.points.length - 1
            : selection < 0 ? 0 : selection + (event.key === 'ArrowRight' ? 1 : -1);
        select(index);
    });
    function showCustom() {
        const custom = period.value === 'custom';
        document.getElementById('chart-from-label').hidden = !custom;
        document.getElementById('chart-to-label').hidden = !custom;
        from.required = to.required = custom;
    }
    async function load() {
        controller?.abort();
        const current = new AbortController(); controller = current;
        const url = new URL(root.dataset.endpoint, window.location.href);
        url.searchParams.set('period', period.value);
        url.searchParams.set('gap_minutes', gap.value);
        if (period.value === 'custom') {url.searchParams.set('from', from.value); url.searchParams.set('to', to.value);}
        message.textContent = 'Обновляем график…';
        plot.setAttribute('aria-busy', 'true');
        document.getElementById('period-report').setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin',
                cache: 'no-store', signal: current.signal});
            if (response.status === 401 || response.redirected) throw new Error('Сеанс завершён. Обновите страницу и войдите снова.');
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || 'Не удалось загрузить график.');
            data = result; selection = -1; draft = false;
            count.textContent = `${data.points.length} замеров`;
            message.textContent = description();
            const profile = data.threshold_profile === 'night' ? 'Ночной' : 'Дневной';
            thresholds.textContent = `Текущие границы: ${profile} · ${data.thresholds.lower}–${data.thresholds.upper} уд/мин · контроль ${data.thresholds.enabled ? 'включён' : 'отключён'}`;
            if (!from.value) from.value = localInput(new Date(data.from_ms), data.timezone);
            if (!to.value) to.value = localInput(new Date(data.to_ms), data.timezone);
            render(); renderReport();
        } catch (error) {
            if (error.name === 'AbortError') return;
            message.textContent = `${error.message || 'Не удалось загрузить график.'}${data ? ' На графике и в отчёте остались ранее загруженные данные.' : ''}`;
            if (!data) count.textContent = 'Нет данных';
            document.getElementById('report-period').textContent = data
                ? `Отчёт не обновился. Показан период: ${fullTime(data.from_ms)} — ${fullTime(data.to_ms)} · ${data.timezone}`
                : 'Не удалось загрузить отчёт. Нажмите «Показать», чтобы повторить.';
        } finally {
            if (controller === current) {
                plot.setAttribute('aria-busy', 'false');
                document.getElementById('period-report').setAttribute('aria-busy', 'false');
            }
        }
    }
    period.addEventListener('change', () => {showCustom(); draft = true; if (period.value !== 'custom') load();});
    gap.addEventListener('change', () => {draft = true; if (period.value !== 'custom') load();});
    from.addEventListener('input', () => {draft = true;}); to.addEventListener('input', () => {draft = true;});
    form.addEventListener('submit', event => {event.preventDefault(); load();});
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(() => render()).observe(plot);
    setInterval(() => {
        if (document.getElementById('auto-refresh')?.checked && !document.hidden && !draft
            && !form.contains(document.activeElement) && document.activeElement !== plot) load();
    }, 60_000);
    document.addEventListener('grandma-measurement-completed', () => { if (!draft) load(); });
    showCustom(); load();
})();
