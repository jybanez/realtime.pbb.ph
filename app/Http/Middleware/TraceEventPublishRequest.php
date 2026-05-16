<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceEventPublishRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('realtime.event_publish_trace_enabled', false)) {
            return $next($request);
        }

        $traceId = 'evtpub_' . Str::lower((string) Str::ulid());
        $startedAt = microtime(true);
        $preTraceBootstrapMs = defined('LARAVEL_START')
            ? round(max(0, ($startedAt - LARAVEL_START) * 1000), 3)
            : null;
        $dbTotalMs = 0.0;
        $dbQueries = [];

        $request->attributes->set('event_publish_trace_id', $traceId);
        $request->attributes->set('event_publish_trace_enabled', true);
        $request->attributes->set('event_publish_started_at', $startedAt);
        $request->attributes->set('event_publish_stage_marks', []);

        DB::listen(static function (QueryExecuted $query) use (&$dbTotalMs, &$dbQueries): void {
            $elapsedMs = (float) $query->time;
            $dbTotalMs += $elapsedMs;
            $dbQueries[] = [
                'sql' => $query->sql,
                'time_ms' => round($elapsedMs, 3),
            ];
        });

        $response = $next($request);

        $totalMs = round((microtime(true) - $startedAt) * 1000, 3);
        $slowestQueries = collect($dbQueries)
            ->sortByDesc('time_ms')
            ->take(5)
            ->values()
            ->all();

        Log::info('Realtime event publish request trace.', [
            'trace_id' => $traceId,
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'total_ms' => $totalMs,
            'pre_trace_bootstrap_ms' => $preTraceBootstrapMs,
            'bootstrap_marks' => $GLOBALS['realtime_bootstrap_trace'] ?? [],
            'db_total_ms' => round($dbTotalMs, 3),
            'db_query_count' => count($dbQueries),
            'slowest_queries' => $slowestQueries,
            'controller_marks' => $request->attributes->get('event_publish_stage_marks', []),
            'client_code' => trim((string) $request->input('client_code', '')),
            'project_code' => trim((string) $request->input('project_code', '')),
            'room' => trim((string) $request->input('room', '')),
            'event_type' => trim((string) $request->input('event_type', '')),
        ]);

        $response->headers->set('X-Realtime-Trace-Id', $traceId);

        return $response;
    }
}
