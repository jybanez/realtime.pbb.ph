<?php

namespace App\Http\Controllers;

use App\Realtime\Auth\RealtimeTokenValidationException;
use App\Realtime\Auth\RealtimeTokenValidator;
use App\Realtime\Observability\RealtimeMetrics;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RealtimeSessionController extends Controller
{
    public function store(
        Request $request,
        RealtimeTokenValidator $validator,
        RealtimeMetrics $metrics,
        RealtimeUsageTelemetry $telemetry
    ): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {
            $claims = $validator->validate($data['token']);
        } catch (RealtimeTokenValidationException $e) {
            $metrics->increment('auth.failure');
            $telemetry->record('auth.failure', null, errorCount: 1);
            Log::warning('Realtime session admission rejected.', [
                'service' => config('realtime.service_name'),
                'reason' => $e->reason,
            ]);

            return response()->json([
                'service' => config('realtime.service_name'),
                'status' => 'rejected',
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], 401);
        }

        if (!$claims->hasCapability('session.connect')) {
            $metrics->increment('auth.failure');
            $telemetry->record('auth.failure', $claims, errorCount: 1);
            Log::warning('Realtime session admission rejected.', [
                'service' => config('realtime.service_name'),
                'reason' => 'missing-capability',
            ]);

            return response()->json([
                'service' => config('realtime.service_name'),
                'status' => 'rejected',
                'reason' => 'missing-capability',
                'message' => 'The realtime token does not allow session establishment.',
            ], 401);
        }

        $metrics->increment('auth.success');
        $telemetry->record('auth.success', $claims);

        return response()->json([
            'service' => config('realtime.service_name'),
            'status' => 'accepted',
            'session' => [
                'session_id' => $claims->tokenId,
                'project_code' => $claims->projectCode,
                'app_code' => $claims->appCode,
                'user_id' => $claims->userId,
                'capabilities' => $claims->capabilities,
                'allowed_rooms' => $claims->allowedRooms,
                'allowed_room_prefixes' => $claims->allowedRoomPrefixes,
                'expires_at' => $claims->expiresAt->format(DATE_ATOM),
            ],
        ]);
    }
}
