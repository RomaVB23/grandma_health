<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Services\WatchStatus;

class WatchStatusController
{
    public function __invoke(WatchStatus $status): JsonResponse
    {
        return response()->json($status->snapshot());
    }
}
