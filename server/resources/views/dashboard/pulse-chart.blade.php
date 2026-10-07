<section class="panel chart-panel" id="pulse-chart" data-endpoint="{{ route('dashboard.pulse-chart') }}">
    <div class="history-heading"><div><h2>Пульс во времени</h2><p class="muted">Уникальные замеры по времени измерения. Период графика выбирается отдельно от таблицы.</p></div><span class="count-label" id="chart-count">Загрузка…</span></div>
    <form class="chart-controls" id="chart-form">
        <label>Период<select id="chart-period" name="period"><option value="1h">Последний час</option><option value="6h">Последние 6 часов</option><option value="24h" selected>Последние сутки</option><option value="custom">Свой период (до 31 дня)</option></select></label>
        <label id="chart-from-label" hidden>Начало<input type="datetime-local" id="chart-from" name="from"></label>
        <label id="chart-to-label" hidden>Окончание<input type="datetime-local" id="chart-to" name="to"></label>
        <label>Разрыв при паузе<select id="chart-gap" name="gap_minutes"><option value="5">Больше 5 минут</option><option value="10" selected>Больше 10 минут</option><option value="15">Больше 15 минут</option><option value="30">Больше 30 минут</option></select></label>
        <button class="button primary" type="submit">Показать</button>
    </form>
    <p id="chart-message" class="chart-message muted" role="status" aria-live="polite">Загружаем историю замеров…</p>
    <div class="chart-plot" id="chart-plot" tabindex="0" role="group" aria-label="График пульса. Стрелки влево и вправо выбирают замер, Home и End — первый и последний.">
        <svg id="chart-svg" role="img" aria-label="График уникальных замеров пульса"></svg>
        <div id="chart-tooltip" class="chart-tooltip" hidden></div>
    </div>
    <div class="chart-legend"><span><i class="legend-pulse"></i>Измеренный пульс</span><span><i class="legend-gap"></i>Длительная пауза без замеров</span><span id="chart-thresholds"></span></div>
    <p class="small muted chart-note">Линия соединяет отдельные замеры, а не непрерывное измерение. Разрывы относятся к отсутствию замеров пульса, а не обязательно к потере связи. Границы взяты из настроек контроля. Наведите на график или используйте стрелки клавиатуры.</p>
    <noscript><p class="notice">Для графика включите JavaScript. Таблица замеров доступна ниже.</p></noscript>
</section>
