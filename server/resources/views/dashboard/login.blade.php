@extends('dashboard.layout')
@section('title', 'Вход · Grandma Health')
@section('content')
<section class="login-card panel">
    <p class="eyebrow">Веб-интерфейс сервера</p>
    <h1>История измерений</h1>
    <p class="muted">Войдите, чтобы посмотреть показания и события часов.</p>
    @if (!$configured)
        <div class="notice">Веб-интерфейс ещё не настроен. Задайте пароль на компьютере с сервером.</div>
    @else
        @if (isset($loginError))<div class="notice error" role="alert">{{ $loginError }}</div>@endif
        <form method="post" action="{{ route('dashboard.authenticate') }}" class="login-form">
            @csrf
            <label for="password">Пароль веб-интерфейса</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
            <button class="button primary" type="submit">Войти</button>
        </form>
        <p class="small muted">Сеанс завершается после 30 минут бездействия.</p>
    @endif
</section>
@endsection
