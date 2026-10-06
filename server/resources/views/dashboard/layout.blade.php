<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Grandma Health')</title>
    <link rel="stylesheet" href="{{ asset('dashboard-assets/dashboard.css') }}">
    <script src="{{ asset('dashboard-assets/dashboard.js') }}" defer></script>
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <a class="brand" href="{{ route('dashboard.index') }}"><span class="brand-mark" aria-hidden="true">♥</span><span>Grandma <strong>Health</strong><small>Локальный мониторинг</small></span></a>
        @yield('header')
    </header>
    <main>@yield('content')</main>
    <footer>Grandma Health <span>История показаний часов · данные хранятся на вашем сервере</span></footer>
</div>
</body>
</html>
