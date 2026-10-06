<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WatchHistory
{
    public function query(array $filters, string $timezone): Builder
    {
        $query = DB::table('watch_events')->where('device_id', config('telemetry.device_id'));
        $measurements = $filters['mode'] === 'measurements';
        $timeColumn = $measurements ? 'measured_at_ms' : 'watch_sent_at_ms';
        if ($measurements) {
            // A heartbeat may carry an already measured pulse. Keep its first stored
            // occurrence if the original measurement packet never reached the server.
            $ids = DB::table('watch_events')->where('device_id', config('telemetry.device_id'))
                ->whereNotNull('bpm')->whereNotNull('measured_at_ms')
                ->selectRaw('MIN(id)')->groupBy('measured_at_ms', 'bpm');
            $query->whereIn('id', $ids);
        } elseif ($filters['source'] !== 'all') {
            $query->where('source', $filters['source']);
        }
        if ($filters['from'] !== null) {
            $query->where($timeColumn, '>=', CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone)->getTimestampMs());
        }
        if ($filters['to'] !== null) {
            // Exclusive next-day boundary handles subsecond samples and timezone offsets.
            $query->where($timeColumn, '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone)->addDay()->getTimestampMs());
        }
        return $query;
    }
}
