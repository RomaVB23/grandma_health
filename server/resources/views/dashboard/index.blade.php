@extends('dashboard.layout')
@section('title', 'История измерений · Grandma Health')
@section('header')
<div class="header-actions"><span class="local-badge">Локальный сервер</span><form method="post" action="{{ route('dashboard.logout') }}">@csrf<button class="button subtle" type="submit">Выйти</button></form></div>
@endsection
@section('content')
<section class="page-heading">
    <div><p class="eyebrow">Данные с часов</p><h1>История измерений</h1><p class="muted">Показания во времени, а не только последнее значение.</p></div>
    <div class="refresh-controls"><div class="toolbar-buttons"><a class="button" href="{{ request()->fullUrl() }}">↻ Обновить</a><a class="button" href="{{ route('dashboard.monitoring') }}">Настройки контроля</a><a class="button danger-subtle" href="{{ route('dashboard.clear') }}">Очистить историю</a></div><label class="check-label"><input id="auto-refresh" type="checkbox"> Каждую минуту</label></div>
</section>
@if (session('history_cleared'))
<div class="notice success" role="status">История очищена: удалено {{ session('history_cleared.count') }} пакетов. Новый период начат {{ $text->time(session('history_cleared.cleared_at_ms')) }}. Новые данные будут появляться автоматически.</div>
@endif
<div class="status-grid">
    <section class="panel status-card"><div class="card-label">Последний пульс <span aria-hidden="true">♡</span></div><div class="card-value">{{ $status['bpm'] ?? '—' }} <small>уд/мин</small></div><p>Измерен {{ $text->time($status['measured_at_ms']) }}</p><span class="pill {{ $status['measurement_stale'] ? 'amber' : 'neutral' }}">{{ $status['measurement_age_ms'] === null ? 'Измерений ещё нет' : 'Прошло '.$text->age($status['measurement_age_ms']) }}</span></section>
    <section class="panel status-card"><div class="card-label">Связь с часами <span aria-hidden="true">⌁</span></div><div class="card-value word-value">{{ $status['contact_recent'] ? 'Есть сигнал' : 'Нет свежего сигнала' }}</div><p>Последний {{ $text->time($status['last_live_contact_at_ms']) }}</p><span class="pill {{ $status['contact_recent'] ? 'green' : 'amber' }}">{{ $status['contact_age_ms'] === null ? 'Ожидаем первый сигнал' : 'Прошло '.$text->age($status['contact_age_ms']) }}</span></section>
    <section class="panel status-card"><div class="card-label">Заряд часов <span aria-hidden="true">▱</span></div><div class="card-value">{{ $status['battery_percent'] ?? '—' }}<small>%</small></div><p>Данные {{ $text->time($status['snapshot_at_ms']) }}</p><span class="pill neutral">{{ $status['charging'] === null ? 'Нет данных о зарядке' : ($status['charging'] ? 'Заряжаются' : 'Без зарядки') }} · {{ $text->monitoring($status['monitoring_status']) }}</span></section>
    <section class="panel status-card"><div class="card-label">Ношение часов <span aria-hidden="true">⌚</span></div><div class="card-value word-value">{{ $text->wearing($status['wearing_state']) }}</div><p>Данные {{ $text->time($status['wearing_reported_at_ms']) }}</p><span class="pill {{ $status['wearing_state'] === 'off' ? 'amber' : 'neutral' }}">{{ $status['wearing_state'] === 'unknown' ? 'Нужен свежий сигнал датчика' : 'Этот статус определён датчиком' }}</span></section>
</div>
<p class="control-note">{{ $text->pulseControl($status['pulse_control_status']) }} · границы {{ $status['pulse_lower'] }}–{{ $status['pulse_upper'] }} уд/мин. <a class="text-link" href="{{ route('dashboard.monitoring') }}">Настроить</a></p>
<p class="snapshot-note">Состояние на {{ $text->time($status['server_time_ms']) }} · {{ $timezone }}. Свежая связь не означает новое измерение пульса.</p>

@include('dashboard.pulse-chart')

<section class="panel history-panel">
    <div class="history-heading"><div><h2>Журнал показаний</h2><p class="muted">{{ $filters['mode'] === 'measurements' ? 'Один замер — одна строка, даже при повторной передаче.' : 'Все сохранённые пакеты, включая heartbeat с последним известным пульсом.' }}</p></div><span class="count-label">{{ number_format($events->total(), 0, ',', ' ') }} {{ $filters['mode'] === 'measurements' ? 'замеров' : 'событий' }}</span></div>
    <nav class="tabs" aria-label="Режим истории">
        <a class="{{ $filters['mode'] === 'measurements' ? 'selected' : '' }}" href="{{ route('dashboard.index', array_replace(request()->except('page'), ['mode' => 'measurements', 'source' => 'all'])) }}">Замеры пульса</a>
        <a class="{{ $filters['mode'] === 'events' ? 'selected' : '' }}" href="{{ route('dashboard.index', array_replace(request()->except('page'), ['mode' => 'events'])) }}">Все события</a>
    </nav>
    @if ($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
    <form method="get" action="{{ route('dashboard.index') }}" class="filters">
        <input type="hidden" name="mode" value="{{ $filters['mode'] }}">
        <label>С даты<input type="date" name="from" value="{{ $filters['from'] }}"></label>
        <label>По дату включительно<input type="date" name="to" value="{{ $filters['to'] }}"></label>
        @if ($filters['mode'] === 'events')
        <label>Тип события<select name="source"><option value="all" @selected($filters['source'] === 'all')>Все типы</option><option value="measurement" @selected($filters['source'] === 'measurement')>Измерение</option><option value="heartbeat" @selected($filters['source'] === 'heartbeat')>Heartbeat</option></select></label>
        @endif
        <label>Строк на странице<select name="per_page">@foreach ([25, 50, 100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }}</option>@endforeach</select></label>
        <button class="button primary" type="submit">Показать</button>
        <a class="text-link" href="{{ route('dashboard.index', ['mode' => $filters['mode'], 'from' => '', 'to' => '']) }}">За всё время</a>
    </form>
    <div class="summary-strip"><span>Пульс за период · уникальные замеры</span><strong>{{ $summary->count }}</strong><span>Мин.</span><strong>{{ $summary->min_bpm ?? '—' }}</strong><span>Средний</span><strong>{{ $summary->avg_bpm === null ? '—' : number_format($summary->avg_bpm, 1, ',', '') }}</strong><span>Макс.</span><strong>{{ $summary->max_bpm ?? '—' }}</strong><span class="summary-unit">уд/мин</span></div>
    <div class="table-scroll">
        <table>
            <caption class="sr-only">{{ $filters['mode'] === 'measurements' ? 'Уникальные замеры пульса' : 'Все события часов' }}</caption>
            <thead><tr><th scope="col">Время измерения</th><th scope="col">Пульс, уд/мин</th><th scope="col">Заряд при передаче</th><th scope="col">Отправлено часами</th><th scope="col">Получено сервером</th><th scope="col">Тип пакета</th><th scope="col">Детали</th></tr></thead>
            <tbody>
            @forelse ($events as $event)
                <tr><td class="timestamp">{{ $text->time($event->measured_at_ms) }}</td><td><strong class="pulse-cell">{{ $event->bpm ?? '—' }}</strong>@if ($filters['mode'] === 'events' && $event->source === 'heartbeat' && $event->bpm !== null)<small class="cell-note">Последний известный</small>@endif</td><td>{{ $event->battery_percent === null ? '—' : $event->battery_percent.'%' }}@if ($event->charging)<small class="cell-note">Заряжаются</small>@endif</td><td class="timestamp">{{ $text->time($event->watch_sent_at_ms) }}</td><td class="timestamp">{{ $text->time($event->server_received_at_ms) }}</td><td><span class="pill {{ $event->source === 'measurement' ? 'violet' : 'neutral' }}">{{ $event->source === 'measurement' ? 'Измерение' : 'Heartbeat' }}</span></td><td><details><summary>Открыть</summary><dl class="event-details"><dt>Получено телефоном</dt><dd>{{ $text->time($event->received_at_ms) }}</dd><dt>Ношение при передаче</dt><dd>{{ $text->wearing($event->wearing_state) }}</dd><dt>Состояние отмечено часами</dt><dd>{{ $text->time($event->wearing_since_ms) }}</dd><dt>Мониторинг</dt><dd>{{ $text->monitoring($event->monitoring_status) }}</dd><dt>Свежая связь при приёме</dt><dd>{{ $event->live_contact ? 'Да' : 'Нет' }}</dd><dt>ID записи</dt><dd>{{ $event->id }}</dd><dt>ID события</dt><dd class="identifier">{{ $event->event_id }}</dd></dl></details></td></tr>
            @empty
                <tr><td colspan="7" class="empty-state"><span aria-hidden="true">◷</span><h3>За этот период записей нет</h3><p>Выберите другой период или нажмите «За всё время».</p></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination"><span class="muted">@if ($events->total() > 0)Строки {{ $events->firstItem() ?? 0 }}–{{ $events->lastItem() ?? 0 }} из {{ number_format($events->total(), 0, ',', ' ') }}@else 0 строк @endif</span><div>@if ($events->previousPageUrl())<a class="button" href="{{ $events->previousPageUrl() }}">← Новее</a>@endif<span class="page-number">Страница {{ $events->currentPage() }}</span>@if ($events->nextPageUrl())<a class="button" href="{{ $events->nextPageUrl() }}">Старее →</a>@endif</div></div>
</section>
<p class="small muted bottom-note">В режиме замеров показывается первая сохранённая копия каждого измерения. Заряд относится ко времени передачи пакета. Сводка пульса считается по уникальным замерам за выбранные даты; фильтр типа пакета применяется только к таблице событий.</p>
@endsection
