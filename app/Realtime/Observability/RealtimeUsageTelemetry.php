<?php

namespace App\Realtime\Observability;

use App\Models\RealtimeUsageBucket;
use App\Realtime\Auth\RealtimeTokenClaims;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RealtimeUsageTelemetry
{
    public function record(
        string $eventType,
        ?RealtimeTokenClaims $claims = null,
        int $bytesIn = 0,
        int $bytesOut = 0,
        int $eventCount = 1,
        int $errorCount = 0,
        int $rateLimitedCount = 0,
        ?string $clientCode = null,
        ?string $projectCode = null
    ): void {
        $bucketStart = CarbonImmutable::now('UTC')->startOfHour();
        $key = [
            'bucket_start' => $bucketStart,
            'bucket_granularity' => 'hour',
            'client_code' => substr(trim((string) ($clientCode ?? $claims?->appCode ?? '')), 0, 64),
            'project_code' => substr(trim((string) ($projectCode ?? $claims?->projectCode ?? '')), 0, 64),
            'event_type' => trim($eventType) !== '' ? $eventType : 'system.unknown',
        ];

        RealtimeUsageBucket::query()->firstOrCreate($key, [
            'event_count' => 0,
            'bytes_in' => 0,
            'bytes_out' => 0,
            'error_count' => 0,
            'rate_limited_count' => 0,
        ]);

        RealtimeUsageBucket::query()
            ->where($key)
            ->update([
                'event_count' => DB::raw('event_count + ' . max(0, $eventCount)),
                'bytes_in' => DB::raw('bytes_in + ' . max(0, $bytesIn)),
                'bytes_out' => DB::raw('bytes_out + ' . max(0, $bytesOut)),
                'error_count' => DB::raw('error_count + ' . max(0, $errorCount)),
                'rate_limited_count' => DB::raw('rate_limited_count + ' . max(0, $rateLimitedCount)),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, int>
     */
    public function summarizeLastHours(int $hours = 24): array
    {
        $query = $this->baseWindowQuery($hours);

        return [
            'event_count' => (int) $query->sum('event_count'),
            'bytes_in' => (int) $query->sum('bytes_in'),
            'bytes_out' => (int) $query->sum('bytes_out'),
            'error_count' => (int) $query->sum('error_count'),
            'rate_limited_count' => (int) $query->sum('rate_limited_count'),
        ];
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function topClientsLastHours(int $hours = 24, int $limit = 5): array
    {
        return $this->baseWindowQuery($hours)
            ->select('client_code')
            ->selectRaw('SUM(event_count) as event_count')
            ->selectRaw('SUM(bytes_in) as bytes_in')
            ->selectRaw('SUM(bytes_out) as bytes_out')
            ->where('client_code', '<>', '')
            ->groupBy('client_code')
            ->orderByDesc('event_count')
            ->limit($limit)
            ->get()
            ->map(fn (RealtimeUsageBucket $row) => [
                'client_code' => $row->client_code,
                'event_count' => (int) $row->event_count,
                'bytes_in' => (int) $row->bytes_in,
                'bytes_out' => (int) $row->bytes_out,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function topProjectsLastHours(int $hours = 24, int $limit = 5): array
    {
        return $this->baseWindowQuery($hours)
            ->select('project_code')
            ->selectRaw('SUM(event_count) as event_count')
            ->selectRaw('SUM(bytes_in) as bytes_in')
            ->selectRaw('SUM(bytes_out) as bytes_out')
            ->where('project_code', '<>', '')
            ->groupBy('project_code')
            ->orderByDesc('event_count')
            ->limit($limit)
            ->get()
            ->map(fn (RealtimeUsageBucket $row) => [
                'project_code' => $row->project_code,
                'event_count' => (int) $row->event_count,
                'bytes_in' => (int) $row->bytes_in,
                'bytes_out' => (int) $row->bytes_out,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    public function eventMixLastHours(int $hours = 24): array
    {
        return $this->baseWindowQuery($hours)
            ->select('event_type')
            ->selectRaw('SUM(event_count) as event_count')
            ->selectRaw('SUM(bytes_in) as bytes_in')
            ->selectRaw('SUM(error_count) as error_count')
            ->selectRaw('SUM(rate_limited_count) as rate_limited_count')
            ->groupBy('event_type')
            ->orderByDesc('event_count')
            ->get()
            ->map(fn (RealtimeUsageBucket $row) => [
                'event_type' => $row->event_type,
                'event_count' => (int) $row->event_count,
                'bytes_in' => (int) $row->bytes_in,
                'error_count' => (int) $row->error_count,
                'rate_limited_count' => (int) $row->rate_limited_count,
            ])
            ->all();
    }

    private function baseWindowQuery(int $hours)
    {
        $start = CarbonImmutable::now('UTC')->subHours(max(1, $hours))->startOfHour();

        return RealtimeUsageBucket::query()
            ->where('bucket_granularity', 'hour')
            ->where('bucket_start', '>=', $start);
    }
}
