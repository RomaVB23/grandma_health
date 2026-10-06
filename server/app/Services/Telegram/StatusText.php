<?php

namespace App\Services\Telegram;

use DateTimeImmutable;
use DateTimeZone;

class StatusText
{
    public function format(array $s): string
    {
        $pulse = $s['bpm'] === null ? 'измерений ещё нет' : $s['bpm'].' уд/мин';
        $pulseAge = $s['measurement_age_ms'] === null ? '—' : $this->age($s['measurement_age_ms']);
        $connection = $s['last_live_contact_at_ms'] === null ? 'свежий сигнал ещё не получен'
            : ($s['contact_recent'] ? 'есть свежий сигнал' : 'нет свежего сигнала ≥ 10 мин');
        $battery = $s['battery_percent'] === null ? 'нет данных' : $s['battery_percent'].'%'.($s['charging'] ? ' · заряжаются' : '');
        $monitoring = match ($s['monitoring_status']) {
            'active' => 'включён', 'starting' => 'запускается', 'stopped' => 'выключен',
            'permission_lost' => 'нет разрешения', 'unsupported' => 'не поддерживается',
            'error' => 'ошибка', default => 'статус неизвестен',
        };

        return "📊 Состояние на ".$this->time($s['server_time_ms'])."\n\n"
            ."❤️ Последний пульс: $pulse\n"
            .'Измерен: '.$this->time($s['measured_at_ms'])."\nПрошло с измерения: $pulseAge"
            .($s['measurement_stale'] ? ' · значение устарело' : '')."\n\n"
            ."⌚ Связь: $connection\nПоследний сигнал: ".$this->time($s['last_live_contact_at_ms'])."\n\n"
            ."🔋 Заряд часов: $battery\nДанные заряда: ".$this->time($s['snapshot_at_ms'])."\n"
            ."Мониторинг: $monitoring (последний известный статус)\n\n"
            .'Это последние полученные данные. Новое измерение на часах этой кнопкой пока не запускается.';
    }

    private function time(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return '—';
        }
        return (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))
            ->setTimezone(new DateTimeZone(config('telegram.timezone')))->format('d.m.Y H:i:s');
    }

    private function age(int $milliseconds): string
    {
        $seconds = intdiv($milliseconds, 1000);
        return intdiv($seconds, 60).' мин '.($seconds % 60).' с';
    }
}
