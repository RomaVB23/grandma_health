<?php

namespace App\Http\Controllers;

use App\Services\MonitoringSettings;
use App\Services\TelemetryEpoch;
use Illuminate\Http\Request;

class MonitoringSettingsController
{
    public function show(MonitoringSettings $settings)
    {
        $rules = $settings->get();
        return view('dashboard.monitoring', ['rules' => $rules, 'profile' => $settings->effective(),
            'profileDescription' => $settings->description(), 'timezoneGroups' => $this->timezoneGroups($rules->profile_timezone)]);
    }

    private function timezoneGroups(string $current): array
    {
        $common = ['Europe/Minsk' => 'Минск · Беларусь', 'Europe/Kaliningrad' => 'Калининград',
            'Europe/Moscow' => 'Москва, Санкт-Петербург', 'Europe/Samara' => 'Самара',
            'Asia/Yekaterinburg' => 'Екатеринбург', 'Asia/Omsk' => 'Омск',
            'Asia/Krasnoyarsk' => 'Красноярск', 'Asia/Irkutsk' => 'Иркутск',
            'Asia/Yakutsk' => 'Якутск', 'Asia/Vladivostok' => 'Владивосток',
            'Asia/Magadan' => 'Магадан', 'Asia/Kamchatka' => 'Петропавловск-Камчатский'];
        $other = array_diff_key(array_fill_keys(\DateTimeZone::listIdentifiers(), ''), $common);
        // Keep a previously configured alias selectable rather than silently replacing it.
        if (!isset($common[$current]) && !isset($other[$current])) $other[$current] = '';
        $now = \Carbon\CarbonImmutable::now();
        $label = static function (string $zone, string $name) use ($now): string {
            $minutes = intdiv((new \DateTimeZone($zone))->getOffset($now), 60);
            $offset = sprintf('UTC%s%02d:%02d', $minutes < 0 ? '−' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
            return ($name ?: str_replace('_', ' ', $zone)).' · '.$offset;
        };
        foreach ($common as $zone => $name) $common[$zone] = $label($zone, $name);
        foreach ($other as $zone => $name) $other[$zone] = $label($zone, $name);
        return ['Россия и Беларусь' => $common, 'Другие часовые пояса' => $other];
    }

    public function save(Request $request, MonitoringSettings $settings, TelemetryEpoch $epochs)
    {
        $values = $request->validate([
            'pulse_enabled' => ['sometimes', 'boolean'], 'wearing_enabled' => ['sometimes', 'boolean'],
            'pulse_lower' => ['required', 'integer', 'between:1,999'],
            'pulse_upper' => ['required', 'integer', 'between:2,1000', 'gt:pulse_lower'],
            'night_pulse_lower' => ['required', 'integer', 'between:1,999'],
            'night_pulse_upper' => ['required', 'integer', 'between:2,1000', 'gt:night_pulse_lower'],
            'night_start' => ['required', 'date_format:H:i'],
            'night_end' => ['required', 'date_format:H:i', 'different:night_start'],
            'profile_timezone' => ['required', 'timezone'],
            'confirmation_samples' => ['required', 'integer', 'between:1,5'],
            'pulse_max_age_seconds' => ['required', 'integer', 'between:60,900'],
            'confirmation_gap_minutes' => ['required', 'integer', 'between:1,120'],
            'off_wrist_minutes' => ['required', 'integer', 'between:1,120'],
            'version' => ['required', 'integer', 'min:0'],
        ], ['pulse_upper.gt' => 'Верхняя дневная граница должна быть больше нижней.',
            'night_pulse_upper.gt' => 'Верхняя ночная граница должна быть больше нижней.',
            'night_end.different' => 'Начало и окончание ночного периода должны различаться.']);
        $version = (int) $values['version']; unset($values['version']);
        $values['pulse_enabled'] = $request->boolean('pulse_enabled');
        $values['wearing_enabled'] = $request->boolean('wearing_enabled');
        $settings->save($values, $epochs, $version);
        return redirect()->route('dashboard.monitoring')->with('settings_saved', true);
    }

    public function mode(Request $request, MonitoringSettings $settings, TelemetryEpoch $epochs)
    {
        $data = $request->validate(['mode' => ['required', \Illuminate\Validation\Rule::in(['auto', 'day', 'night'])],
            'version' => ['required', 'integer', 'min:0']]);
        $settings->setMode($data['mode'], $epochs, (int) $data['version']);
        return redirect()->route('dashboard.monitoring')->with('mode_saved', true);
    }
}
