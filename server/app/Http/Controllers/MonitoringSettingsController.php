<?php

namespace App\Http\Controllers;

use App\Services\MonitoringSettings;
use App\Services\TelemetryEpoch;
use Illuminate\Http\Request;

class MonitoringSettingsController
{
    public function show(MonitoringSettings $settings)
    {
        return view('dashboard.monitoring', ['rules' => $settings->get()]);
    }

    public function save(Request $request, MonitoringSettings $settings, TelemetryEpoch $epochs)
    {
        $values = $request->validate([
            'pulse_enabled' => ['sometimes', 'boolean'], 'wearing_enabled' => ['sometimes', 'boolean'],
            'pulse_lower' => ['required', 'integer', 'between:1,999'],
            'pulse_upper' => ['required', 'integer', 'between:2,1000', 'gt:pulse_lower'],
            'confirmation_samples' => ['required', 'integer', 'between:1,5'],
            'pulse_max_age_seconds' => ['required', 'integer', 'between:60,900'],
            'confirmation_gap_minutes' => ['required', 'integer', 'between:1,120'],
            'off_wrist_minutes' => ['required', 'integer', 'between:1,120'],
            'version' => ['required', 'integer', 'min:0'],
        ], ['pulse_upper.gt' => 'Верхняя граница должна быть больше нижней.']);
        $version = (int) $values['version']; unset($values['version']);
        $values['pulse_enabled'] = $request->boolean('pulse_enabled');
        $values['wearing_enabled'] = $request->boolean('wearing_enabled');
        $settings->save($values, $epochs, $version);
        return redirect()->route('dashboard.monitoring')->with('settings_saved', true);
    }
}
