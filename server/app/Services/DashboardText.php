<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

class DashboardText
{
    public function time(?int $milliseconds): string
    {
        return $milliseconds === null ? '—' : (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))
            ->setTimezone(new DateTimeZone(config('dashboard.timezone')))->format('d.m.Y H:i:s');
    }

    public function age(?int $milliseconds): string
    {
        if ($milliseconds === null) { return '—'; }
        $seconds = intdiv(max(0, $milliseconds), 1000);
        if ($seconds >= 86400) { return intdiv($seconds, 86400).' д '.intdiv($seconds % 86400, 3600).' ч'; }
        if ($seconds >= 3600) { return intdiv($seconds, 3600).' ч '.intdiv($seconds % 3600, 60).' мин'; }
        return intdiv($seconds, 60).' мин '.($seconds % 60).' с';
    }

    public function monitoring(string $status): string
    {
        return match ($status) {
            'active' => 'Включён', 'starting' => 'Запускается', 'stopped' => 'Выключен',
            'permission_lost' => 'Нет разрешения', 'unsupported' => 'Не поддерживается',
            'error' => 'Ошибка', default => 'Неизвестно',
        };
    }

    public function wearing(string $state): string
    {
        return match ($state) { 'on' => 'На руке', 'off' => 'Сняты', default => 'Неизвестно' };
    }

    public function pulseControl(string $state): string
    {
        return match ($state) { 'disabled' => 'Контроль выключен', 'out_of_range' => 'Вне заданного диапазона',
            'in_range' => 'В заданном диапазоне', default => 'Ожидаем свежий замер на руке' };
    }
}
