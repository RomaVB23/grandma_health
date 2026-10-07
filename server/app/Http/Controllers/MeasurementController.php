<?php

namespace App\Http\Controllers;

use App\Services\MeasurementRequests;
use App\Services\MeasurementText;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeasurementController
{
    public function create(MeasurementRequests $requests, MeasurementText $text)
    {
        $result = $requests->create();
        return response()->json($result + ['message' => $text->format($result)], 202);
    }

    public function status(string $id, MeasurementRequests $requests, MeasurementText $text)
    {
        $result = $requests->find($id);
        return response()->json($result + ['message' => $text->format($result)]);
    }

    public function claim(Request $request, MeasurementRequests $requests)
    {
        // No transaction is held while long-polling. The phone holds one outgoing connection.
        $query = $request->validate(['wait' => ['sometimes', 'integer', 'between:0,20']]);
        $until = microtime(true) + ($query['wait'] ?? 0);
        do {
            if ($result = $requests->claim()) return response()->json(['request' => $result, 'server_time_ms' => \App\Services\Telegram\BotStore::now()]);
            if (microtime(true) >= $until) break;
            usleep(500_000);
        } while (!connection_aborted());
        return response()->json(['request' => null, 'server_time_ms' => \App\Services\Telegram\BotStore::now()]);
    }

    public function result(Request $request, string $id, MeasurementRequests $requests)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(MeasurementRequests::RESULTS)],
            'bpm' => ['required_if:status,success', 'prohibited_unless:status,success', 'integer', 'between:1,300'],
            'measured_at_ms' => ['required_if:status,success', 'prohibited_unless:status,success', 'integer', 'min:1'],
            'sample_after_request_ms' => ['required_if:status,success', 'prohibited_unless:status,success', 'integer', 'between:1,60000'],
        ]);
        return response()->json($requests->finish($id, $data));
    }
}
