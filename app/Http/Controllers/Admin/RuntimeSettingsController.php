<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Realtime\Settings\RealtimeRuntimeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuntimeSettingsController extends Controller
{
    public function updateMaestroTelemetry(Request $request, RealtimeRuntimeSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && method_exists($user, 'isAdmin') && $user->isAdmin(), 403, 'Admin access required.');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'app_code' => ['required', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'max:4096'],
            'connect_timeout_seconds' => ['required', 'integer', 'min:1', 'max:60'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $settings->updateMaestroTelemetry($validated);

        return response()->json([
            'status' => true,
            'data' => [
                'maestro_telemetry' => $settings->maestroTelemetry(),
            ],
        ]);
    }
}
