@extends('dashboard.layout')
@section('title', 'Настройки контроля · Grandma Health')
@section('content')
<section class="page-heading"><div><p class="eyebrow">Настройки уведомлений</p><h1>Контроль показаний</h1><p class="muted">Настройки сохраняются на сервере и действуют для всех получателей оповещений.</p></div><a class="button" href="{{ route('dashboard.index') }}">← История</a></section>
@if (session('settings_saved'))<div class="notice success" role="status">Настройки сохранены. Контроль начнётся по новым измерениям; старые показания не вызовут тревогу.</div>@endif
@if ($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
<form class="panel monitoring-form" method="post" action="{{ route('dashboard.monitoring.save') }}">
    @csrf<input type="hidden" name="version" value="{{ $rules->changed_at_ms }}">
    <h2>Пульс</h2>
    <p class="muted">Уведомление по показаниям часов сообщает о выходе за ваши границы. Это не диагноз и не подтверждение отсутствия проблемы при значении внутри диапазона.</p>
    <label class="check-label"><input type="hidden" name="pulse_enabled" value="0"><input type="checkbox" name="pulse_enabled" value="1" @checked(old('pulse_enabled', $rules->pulse_enabled))> Включить уведомления о пульсе</label>
    <div class="monitoring-fields">
        <label>Нижняя граница, уд/мин<input type="number" min="1" max="999" name="pulse_lower" value="{{ old('pulse_lower', $rules->pulse_lower) }}" required></label>
        <label>Верхняя граница, уд/мин<input type="number" min="2" max="1000" name="pulse_upper" value="{{ old('pulse_upper', $rules->pulse_upper) }}" required></label>
        <label>Разных замеров для подтверждения<input type="number" min="1" max="5" name="confirmation_samples" value="{{ old('confirmation_samples', $rules->confirmation_samples) }}" required></label>
        <label>Свежесть замера, секунд<input type="number" min="60" max="900" name="pulse_max_age_seconds" value="{{ old('pulse_max_age_seconds', $rules->pulse_max_age_seconds) }}" required></label>
        <label>Максимальный интервал между подтверждающими замерами, минут<input type="number" min="1" max="120" name="confirmation_gap_minutes" value="{{ old('confirmation_gap_minutes', $rules->confirmation_gap_minutes) }}" required></label>
    </div>
    <p class="small muted">Тревога: строго ниже нижней или выше верхней границы. Обе границы входят в диапазон. Для тревоги и возвращения в диапазон нужно указанное число последовательных разных замеров. Каждый замер должен быть свежим при проверке, а интервал между соседними замерами — не больше заданного. Например, при 3 подтверждениях и интервале 15 минут замеры в 20:00, 20:07 и 20:14 подтвердят событие, если каждый пришёл свежим. Замер в другом диапазоне или слишком большой перерыв начинает новую серию. Повторная передача одного замера не считается новым. При снятых часах, неизвестном ношении или отсутствии свежей связи серия сбрасывается. При 1 подтверждении интервал ожидания не используется.</p>
    <h2>Снятие часов</h2>
    <label class="check-label"><input type="hidden" name="wearing_enabled" value="0"><input type="checkbox" name="wearing_enabled" value="1" @checked(old('wearing_enabled', $rules->wearing_enabled))> Сообщать, если часы долго сняты</label>
    <div class="monitoring-fields"><label>Срок до уведомления, минут<input type="number" min="1" max="120" name="off_wrist_minutes" value="{{ old('off_wrist_minutes', $rules->off_wrist_minutes) }}" required></label></div>
    <p class="small muted">Отсчёт начинается, когда сервер получает признак снятия. Потеря связи прерывает отсчёт. Зарядка обычно тоже означает снятые часы. Возвращение на руку подтверждается новым сигналом датчика.</p>
    <p>Сообщения получают активные участники Telegram, которым разрешены оповещения и которые их включили. Список участников сохраняется.</p>
    <button class="button primary" type="submit">Сохранить настройки</button>
</form>
@endsection
