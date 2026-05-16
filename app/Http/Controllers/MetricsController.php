<?php

namespace App\Http\Controllers;

use App\Realtime\Observability\RealtimeMetrics;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    public function __invoke(RealtimeMetrics $metrics): JsonResponse
    {
        return response()->json([
            'service' => config('realtime.service_name'),
            'status' => 'ok',
            'metrics' => $metrics->snapshot(),
        ]);
    }
}
