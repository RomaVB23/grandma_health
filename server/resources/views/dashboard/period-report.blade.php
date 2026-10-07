<section class="period-report" id="period-report" aria-labelledby="report-title" aria-busy="true">
    <div class="report-heading"><div><p class="eyebrow">Проверка наблюдения</p><h3 id="report-title">Отчёт за период графика</h3></div><span class="pill neutral">История часов</span></div>
    <p class="small muted" id="report-period" role="status" aria-live="polite">Загружаем отчёт…</p>
    <div class="report-grid">
        <div class="report-stat"><p class="report-label">Уникальных замеров</p><strong id="report-count">—</strong><p id="report-pulse" class="small muted">Показания пульса</p></div>
        <div class="report-stat"><p class="report-label">Средний промежуток</p><strong id="report-mean-gap">—</strong><p class="small muted">Между соседними замерами</p></div>
        <div class="report-stat"><p class="report-label">Самый большой промежуток</p><strong id="report-max-gap">—</strong><p id="report-max-range" class="small muted">Нужно хотя бы два замера</p></div>
        <div class="report-stat"><p class="report-label">Изменение заряда часов</p><strong id="report-battery-change">—</strong><p id="report-battery-summary" class="small muted">Нужны снимки заряда за период</p></div>
    </div>
    <dl class="report-details">
        <dt>Первый и последний замер</dt><dd id="report-sample-range">—</dd>
        <dt>Длительные паузы между замерами</dt><dd id="report-long-gaps">—</dd>
        <dt>До первого замера / после последнего</dt><dd id="report-edges">—</dd>
        <dt>Первый и последний снимок заряда</dt><dd id="report-battery-range">—</dd>
        <dt>Зарядка в выбранный период</dt><dd id="report-charging">—</dd>
    </dl>
    <p class="small muted report-note">Пауза в замерах не обязательно означает потерю связи. Средний пульс рассчитан по отдельным замерам. Изменение заряда — разница между первым и последним снимком, с учётом возможной зарядки; это не прогноз автономности. Заряд телефона пока не собирается.</p>
</section>
