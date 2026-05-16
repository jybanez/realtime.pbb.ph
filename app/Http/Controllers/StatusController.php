<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StatusController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'service' => config('realtime.service_name'),
            'status' => 'ok',
            'environment' => app()->environment(),
            'laravel' => app()->version(),
        ]);
    }

    public function ready(): JsonResponse
    {
        DB::connection()->getPdo();

        return response()->json([
            'service' => config('realtime.service_name'),
            'status' => 'ready',
            'database' => config('database.default'),
            'audience' => config('realtime.token_audience'),
        ]);
    }

    public function landing(): View
    {
        return view('welcome', [
            'service' => config('realtime.service_name'),
            'environment' => app()->environment(),
            'laravel' => app()->version(),
            'tokenAudience' => config('realtime.token_audience'),
            'wsHost' => config('realtime.ws_host'),
            'wsPort' => config('realtime.ws_port'),
        ]);
    }
}
