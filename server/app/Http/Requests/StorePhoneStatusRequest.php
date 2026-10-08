<?php

namespace App\Http\Requests;

use App\Services\Telegram\BotStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePhoneStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'device_id' => ['required', Rule::in([config('telemetry.device_id')])],
            'battery_percent' => ['required', 'integer', 'between:0,100'],
            'charging' => ['required', 'boolean'],
            'snapshot_at_ms' => ['required', 'integer', 'min:1',
                'max:'.(BotStore::now() + config('telemetry.clock_tolerance_ms'))],
        ];
    }
}
