<?php

namespace App\Http\Controllers;

use App\Realtime\Admin\RealtimeAdminAuditLogger;
use App\Realtime\Ingress\RealtimeEventIngressGate;
use App\Realtime\Ingress\RealtimeEventPublishQueue;
use App\Realtime\Observability\RealtimeUsageTelemetry;
use App\Models\RealtimeServerEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EventPublishController extends Controller
{
    public function store(
        Request $request,
        RealtimeEventIngressGate $gate,
        RealtimeEventPublishQueue $queue,
        RealtimeAdminAuditLogger $audit,
        RealtimeUsageTelemetry $telemetry
    ): JsonResponse {
        $this->markStage($request, 'controller.enter');

        $validated = $request->validate([
            'client_code' => ['required', 'string', 'max:80'],
            'project_code' => ['required', 'string', 'max:80'],
            'room' => ['required', 'string', 'max:180'],
            'event_type' => ['required', 'string', 'max:180'],
            'payload' => ['required', 'array'],
            'meta' => ['nullable', 'array'],
            'event_id' => ['nullable', 'string', 'max:180'],
        ]);
        $this->markStage($request, 'validated');

        $payloadJson = json_encode($validated['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadBytes = is_string($payloadJson) ? strlen($payloadJson) : 0;
        $payloadByteLimit = max(0, (int) config('realtime.event_publish_payload_max_bytes', 32 * 1024));
        $this->markStage($request, 'payload_sized', [
            'payload_bytes' => $payloadBytes,
        ]);

        if ($payloadByteLimit > 0 && $payloadBytes > $payloadByteLimit) {
            $response = response()->json([
                'service' => config('realtime.service_name'),
                'status' => 'rejected',
                'reason' => 'payload-too-large',
                'message' => 'The event payload exceeds the maximum allowed encoded size.',
            ], 422);

            $this->markStage($request, 'response.ready', ['status' => 'payload-too-large']);

            return $response;
        }

        $backendSecret = trim((string) $request->header('X-Realtime-Backend-Secret', ''));
        $this->markStage($request, 'secret.read');
        $authorization = $gate->authorize(
            $validated['client_code'],
            $validated['project_code'],
            $validated['room'],
            $backendSecret,
            $validated['event_type']
        );
        $this->markStage($request, 'authorized', [
            'accepted' => $authorization->accepted,
            'reason' => $authorization->reason,
        ]);

        if (!$authorization->accepted) {
            $clientCode = trim((string) $validated['client_code']);
            $projectCode = trim((string) $validated['project_code']);
            $eventId = trim((string) ($validated['event_id'] ?? 'pending'));
            $room = trim((string) $validated['room']);
            $eventType = trim((string) $validated['event_type']);
            $reason = $authorization->reason;
            $traceId = $this->traceId($request);

            $this->recordAfterResponse(
                $traceId,
                'telemetry.rejected',
                fn () => $telemetry->record(
                    'event.publish.rejected',
                    null,
                    errorCount: 1,
                    clientCode: $clientCode,
                    projectCode: $projectCode
                )
            );

            $this->recordAfterResponse(
                $traceId,
                'audit.rejected',
                fn () => $audit->record(
                    null,
                    'event_publish.rejected',
                    'realtime_server_event',
                    $eventId,
                    before: [],
                    after: [
                        'client_code' => $clientCode,
                        'project_code' => $projectCode,
                        'room' => $room,
                        'event_type' => $eventType,
                    ],
                    reason: $reason,
                    clientCode: $clientCode,
                    projectCode: $projectCode
                )
            );

            $status = match ($authorization->reason) {
                'invalid-backend-secret' => 401,
                'missing-capability', 'room-not-allowed', 'event-type-not-allowed', 'inactive-client', 'inactive-project', 'missing-policy', 'client-project-mismatch' => 403,
                default => 422,
            };

            $response = response()->json([
                'service' => config('realtime.service_name'),
                'status' => 'rejected',
                'reason' => $authorization->reason,
                'message' => $authorization->message,
            ], $status);

            $this->markStage($request, 'response.ready', [
                'status' => 'rejected',
                'reason' => $authorization->reason,
            ]);

            return $response;
        }

        $policy = $authorization->policy;
        $limit = $policy ? $gate->publishLimitPerMinute($policy) : (int) config('realtime.event_publish_rate_limit_per_minute', 60);
        $this->markStage($request, 'rate_limit.resolved', [
            'limit' => $limit,
        ]);
        if ($limit > 0) {
            $windowStart = now()->copy()->startOfMinute();
            $recentCount = RealtimeServerEvent::query()
                ->where('client_code', $validated['client_code'])
                ->where('created_at', '>=', $windowStart)
                ->count();
            $this->markStage($request, 'rate_limit.counted', [
                'recent_count' => $recentCount,
            ]);

            if ($recentCount >= $limit) {
                $clientCode = trim((string) $validated['client_code']);
                $projectCode = trim((string) $validated['project_code']);
                $traceId = $this->traceId($request);

                $this->recordAfterResponse(
                    $traceId,
                    'telemetry.rate_limited',
                    fn () => $telemetry->record(
                        'event.publish.rate_limited',
                        null,
                        errorCount: 1,
                        rateLimitedCount: 1,
                        clientCode: $clientCode,
                        projectCode: $projectCode
                    )
                );

                $response = response()->json([
                    'service' => config('realtime.service_name'),
                    'status' => 'rejected',
                    'reason' => 'rate-limit-exceeded',
                    'message' => 'Backend event publish rate limit exceeded.',
                ], 429);

                $this->markStage($request, 'response.ready', [
                    'status' => 'rate-limit-exceeded',
                ]);

                return $response;
            }
        }

        $event = $queue->enqueue(
            trim((string) $validated['client_code']),
            $authorization->project,
            trim((string) $validated['room']),
            trim((string) $validated['event_type']),
            $validated['payload'],
            is_array($validated['meta'] ?? null) ? $validated['meta'] : [],
            isset($validated['event_id']) ? trim((string) $validated['event_id']) : null
        );
        $this->markStage($request, 'queued', [
            'publish_id' => $event->publish_id,
        ]);

        $publishId = $event->publish_id;
        $clientCode = $event->client_code;
        $projectCode = $event->project_code;
        $room = $event->room;
        $eventType = $event->event_type;
        $eventId = $event->event_id;
        $traceId = $this->traceId($request);

        $this->recordAfterResponse(
            $traceId,
            'audit.accepted',
            fn () => $audit->record(
                null,
                'event_publish.accepted',
                'realtime_server_event',
                $publishId,
                before: [],
                after: [
                    'client_code' => $clientCode,
                    'project_code' => $projectCode,
                    'room' => $room,
                    'event_type' => $eventType,
                    'event_id' => $eventId,
                ],
                reason: null,
                clientCode: $clientCode,
                projectCode: $projectCode
            )
        );
        $this->markStage($request, 'defer.audit_registered');

        $this->recordAfterResponse(
            $traceId,
            'telemetry.accepted',
            fn () => $telemetry->record(
                'event.publish.accepted',
                null,
                eventCount: 1,
                clientCode: $clientCode,
                projectCode: $projectCode
            )
        );
        $this->markStage($request, 'defer.telemetry_registered');

        $response = response()->json([
            'service' => config('realtime.service_name'),
            'status' => 'accepted',
            'data' => [
                'publish_id' => $event->publish_id,
                'client_code' => $event->client_code,
                'project_code' => $event->project_code,
                'room' => $event->room,
                'event_type' => $event->event_type,
                'event_id' => $event->event_id,
                'published' => true,
            ],
        ], 202);
        $this->markStage($request, 'response.built');

        $this->markStage($request, 'response.ready', [
            'status' => 'accepted',
            'publish_id' => $publishId,
        ]);

        return $response;
    }

    private function recordAfterResponse(string $traceId, string $label, callable $callback): void
    {
        if (app()->runningUnitTests()) {
            $callback();
            return;
        }

        dispatch(function () use ($traceId, $label, $callback): void {
            $startedAt = microtime(true);
            $status = 'ok';

            try {
                $callback();
            } catch (\Throwable $exception) {
                $status = 'failed';

                Log::warning('Realtime event publish deferred callback failed.', [
                    'trace_id' => $traceId !== '' ? $traceId : null,
                    'label' => $label,
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                    'message' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ]);

                throw $exception;
            } finally {
                if (config('realtime.event_publish_trace_enabled', false)) {
                    Log::info('Realtime event publish deferred callback trace.', [
                        'trace_id' => $traceId !== '' ? $traceId : null,
                        'label' => $label,
                        'status' => $status,
                        'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
                    ]);
                }
            }
        })->afterResponse();
    }

    private function markStage(Request $request, string $stage, array $context = []): void
    {
        if (!$this->traceEnabled($request)) {
            return;
        }

        $startedAt = (float) $request->attributes->get('event_publish_started_at', microtime(true));
        $marks = $request->attributes->get('event_publish_stage_marks', []);
        $marks[] = array_merge([
            'stage' => $stage,
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 3),
        ], $context);
        $request->attributes->set('event_publish_stage_marks', $marks);
    }

    private function traceId(Request $request): string
    {
        return $this->traceEnabled($request)
            ? (string) $request->attributes->get('event_publish_trace_id', 'evtpub_unknown')
            : '';
    }

    private function traceEnabled(Request $request): bool
    {
        return (bool) $request->attributes->get('event_publish_trace_enabled', false);
    }
}
