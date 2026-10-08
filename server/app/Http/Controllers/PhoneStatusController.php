<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhoneStatusRequest;
use App\Services\TelemetryEpoch;
use App\Services\Telegram\BotStore;
use Illuminate\Support\Facades\DB;

class PhoneStatusController
{
    public function __invoke(StorePhoneStatusRequest $request)
    {
        $data = $request->validated();
        DB::transaction(function () use ($data): void {
            $epoch = app(TelemetryEpoch::class)->lock($data['device_id']);
            $timestamp = (int) $data['snapshot_at_ms'];
            if ($timestamp <= $epoch->cleared_before_ms) { return; }
            $old = DB::table('phone_status')->where('device_id', $data['device_id'])->first();
            // Retries/late requests must not replace or freshen a newer sample.
            if ($old && $timestamp <= $old->snapshot_at_ms) { return; }
            DB::table('phone_status')->updateOrInsert(['device_id' => $data['device_id']], [
                'battery_percent' => (int) $data['battery_percent'], 'charging' => (bool) $data['charging'],
                'snapshot_at_ms' => $timestamp, 'server_received_at_ms' => BotStore::now(),
            ]);
        }, 5);
        return response()->noContent();
    }
}
