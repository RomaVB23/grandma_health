@extends('dashboard.layout')
@section('title', 'Очистка истории · Grandma Health')
@section('header')
<a class="button" href="{{ route('dashboard.index') }}">← Вернуться к истории</a>
@endsection
@section('content')
<section class="panel clear-card">
    <p class="eyebrow">Начало нового периода</p>
    <h1>Очистить историю часов?</h1>
    <p class="muted">Это позволит начать сбор показаний бабушки без ваших тестовых замеров.</p>
    <div class="notice error"><strong>Будут безвозвратно удалены все сохранённые показания и события этих часов за все даты.</strong> Текущий фильтр таблицы не ограничивает очистку.</div>
    <dl class="clear-details"><dt>Устройство</dt><dd>{{ $device }}</dd><dt>Сейчас в базе</dt><dd>{{ number_format($count, 0, ',', ' ') }} пакетов</dd></dl>
    <p class="small muted">Пароль, настройки сервера, участники и их права в Telegram сохранятся. Старые пакеты из очереди телефона не вернутся в историю. Ранее отправленные сообщения Telegram останутся в чатах.</p>
    <p class="small muted">Новые события могут поступать во время подтверждения и также попадут под очистку. После неё сбор продолжится; пульс появится после нового измерения на часах.</p>
    @if ($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('dashboard.clear.confirm') }}" class="clear-form">
        @csrf
        <input type="hidden" name="generation" value="{{ $epoch->generation }}">
        <label for="confirmation">Введите слово ОЧИСТИТЬ<input id="confirmation" name="confirmation" type="text" autocomplete="off" spellcheck="false" required></label>
        <label for="password">Подтвердите паролем веб-интерфейса<input id="password" name="password" type="password" autocomplete="current-password" required></label>
        <div class="toolbar-buttons"><button class="button danger" type="submit">Удалить все показания</button><a class="button" href="{{ route('dashboard.index') }}">Отмена</a></div>
    </form>
</section>
@endsection
