@extends('dashboard.layout')
@section('title', 'Настройки контроля · Grandma Health')
@section('content')
<section class="page-heading"><div><p class="eyebrow">Настройки уведомлений</p><h1>Контроль показаний</h1><p class="muted">Настройки сохраняются на сервере и действуют для всех получателей оповещений.</p></div><a class="button" href="{{ route('dashboard.index') }}">← История</a></section>
@if (session('settings_saved'))<div class="notice success" role="status">Настройки сохранены. Контроль начнётся по новым измерениям; старые показания не вызовут тревогу.</div>@endif
@if ($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
@if (session('mode_saved'))<div class="notice success" role="status">Режим изменён. Веб и Telegram используют общий профиль; контроль продолжится по новым измерениям.</div>@endif
<section class="panel monitoring-form profile-panel">
    <h2>Действующий профиль</h2>
    <p class="profile-summary" role="status">{{ $profileDescription }}</p>
    <form method="post" action="{{ route('dashboard.monitoring.mode') }}" class="profile-buttons">
        @csrf<input type="hidden" name="version" value="{{ $rules->changed_at_ms }}">
        @foreach (['auto' => '🕒 Автоматически', 'day' => '☀️ Дневной', 'night' => '🌙 Ночной'] as $mode => $label)
        <button class="button {{ $profile->effective_mode === $mode ? 'primary' : '' }}" type="submit" name="mode" value="{{ $mode }}" aria-pressed="{{ $profile->effective_mode === $mode ? 'true' : 'false' }}">{{ $label }}</button>
        @endforeach
    </form>
    <p class="small muted">Ручной профиль действует до ближайшей границы расписания, затем включается автоматический режим. Эти кнопки меняют только профиль. Изменения в полях ниже применяются кнопкой «Сохранить настройки».</p>
</section>
<form class="panel monitoring-form" method="post" action="{{ route('dashboard.monitoring.save') }}">
    @csrf<input type="hidden" name="version" value="{{ $rules->changed_at_ms }}">
    <h2>Пульс</h2>
    <p class="muted">Уведомление по показаниям часов сообщает о выходе за ваши границы. Это не диагноз и не подтверждение отсутствия проблемы при значении внутри диапазона.</p>
    <label class="check-label"><input type="hidden" name="pulse_enabled" value="0"><input type="checkbox" name="pulse_enabled" value="1" @checked(old('pulse_enabled', $rules->pulse_enabled))> Включить уведомления о пульсе</label>
    <div class="monitoring-fields">
        <label>Дневная нижняя граница, уд/мин<input type="number" min="1" max="999" name="pulse_lower" value="{{ old('pulse_lower', $rules->pulse_lower) }}" required></label>
        <label>Дневная верхняя граница, уд/мин<input type="number" min="2" max="1000" name="pulse_upper" value="{{ old('pulse_upper', $rules->pulse_upper) }}" required></label>
        <label>Ночная нижняя граница, уд/мин<input type="number" min="1" max="999" name="night_pulse_lower" value="{{ old('night_pulse_lower', $rules->night_pulse_lower) }}" required></label>
        <label>Ночная верхняя граница, уд/мин<input type="number" min="2" max="1000" name="night_pulse_upper" value="{{ old('night_pulse_upper', $rules->night_pulse_upper) }}" required></label>
    </div>
    <p class="small muted">Ночные границы сначала совпадают с дневными. Подберите их отдельно с учётом состояния и лекарств человека; ночной профиль сам по себе не подтверждает сон.</p>
    <h2>Расписание</h2>
    <div class="monitoring-fields">
        <label>Начало ночного периода<input type="time" name="night_start" value="{{ old('night_start', $rules->night_start) }}" required></label>
        <label>Окончание ночного периода<input type="time" name="night_end" value="{{ old('night_end', $rules->night_end) }}" required></label>
        <label>Часовой пояс места проживания
            <select name="profile_timezone" required>
                @foreach ($timezoneGroups as $group => $zones)
                <optgroup label="{{ $group }}">
                    @foreach ($zones as $zone => $label)
                    <option value="{{ $zone }}" @selected(old('profile_timezone', $rules->profile_timezone) === $zone)>{{ $label }}</option>
                    @endforeach
                </optgroup>
                @endforeach
            </select>
        </label>
    </div>
    <p class="small muted">Период может переходить через полночь. Например, 23:00–08:00. Выберите часовой пояс места проживания человека. Смещение UTC указано для текущей даты; сезонные переводы времени учитываются автоматически. Расписание меняет пороги на сервере; частота измерений часов сохраняется.</p>
    <h2>Подтверждения для обоих профилей</h2>
    <div class="monitoring-fields">
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
