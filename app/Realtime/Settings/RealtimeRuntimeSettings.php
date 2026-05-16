<?php

namespace App\Realtime\Settings;

use App\Models\RealtimeRuntimeSetting;
use Illuminate\Support\Facades\Schema;

class RealtimeRuntimeSettings
{
    private const MAESTRO_KEYS = [
        'enabled' => 'maestro_telemetry_enabled',
        'base_url' => 'maestro_base_url',
        'token' => 'maestro_telemetry_token',
        'app_code' => 'maestro_telemetry_app_code',
        'connect_timeout_seconds' => 'maestro_telemetry_connect_timeout_seconds',
        'timeout_seconds' => 'maestro_telemetry_timeout_seconds',
    ];

    /**
     * @return array<string, mixed>
     */
    public function maestroTelemetry(): array
    {
        $defaults = config('realtime.maestro_telemetry', []);
        $stored = $this->values(array_values(self::MAESTRO_KEYS));

        $enabled = $this->boolValue($stored[self::MAESTRO_KEYS['enabled']] ?? null);
        $baseUrl = $this->stringValue($stored[self::MAESTRO_KEYS['base_url']] ?? null);
        $token = $this->stringValue($stored[self::MAESTRO_KEYS['token']] ?? null);
        $appCode = $this->stringValue($stored[self::MAESTRO_KEYS['app_code']] ?? null);
        $connectTimeoutSeconds = $this->intValue($stored[self::MAESTRO_KEYS['connect_timeout_seconds']] ?? null);
        $timeoutSeconds = $this->intValue($stored[self::MAESTRO_KEYS['timeout_seconds']] ?? null);

        return [
            'enabled' => $enabled ?? (bool) ($defaults['enabled'] ?? false),
            'base_url' => $baseUrl ?? (string) ($defaults['base_url'] ?? ''),
            'local_bypass_enabled' => (bool) ($defaults['local_bypass_enabled'] ?? false),
            'local_bypass_base_url' => (string) ($defaults['local_bypass_base_url'] ?? 'http://127.0.0.1'),
            'local_bypass_host' => (string) ($defaults['local_bypass_host'] ?? 'maestro.pbb.ph'),
            'token' => $token ?? (string) ($defaults['token'] ?? ''),
            'token_configured' => ($token ?? (string) ($defaults['token'] ?? '')) !== '',
            'token_header' => (string) ($defaults['token_header'] ?? 'X-Telemetry-Token'),
            'app_code' => $appCode ?? (string) ($defaults['app_code'] ?? 'realtime'),
            'heartbeat_seconds' => (int) ($defaults['heartbeat_seconds'] ?? 15),
            'connect_timeout_seconds' => $connectTimeoutSeconds ?? (int) ($defaults['connect_timeout_seconds'] ?? 3),
            'timeout_seconds' => $timeoutSeconds ?? (int) ($defaults['timeout_seconds'] ?? 5),
            'verify_tls' => (bool) ($defaults['verify_tls'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateMaestroTelemetry(array $values): void
    {
        $updates = [
            self::MAESTRO_KEYS['enabled'] => isset($values['enabled']) ? ($values['enabled'] ? '1' : '0') : null,
            self::MAESTRO_KEYS['base_url'] => $this->stringValue($values['base_url'] ?? null),
            self::MAESTRO_KEYS['app_code'] => $this->stringValue($values['app_code'] ?? null),
            self::MAESTRO_KEYS['connect_timeout_seconds'] => $this->intStringValue($values['connect_timeout_seconds'] ?? null),
            self::MAESTRO_KEYS['timeout_seconds'] => $this->intStringValue($values['timeout_seconds'] ?? null),
        ];

        foreach ($updates as $key => $value) {
            RealtimeRuntimeSetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $value]
            );
        }

        if (array_key_exists('token', $values)) {
            $token = $this->stringValue($values['token']);
            if ($token !== null) {
                RealtimeRuntimeSetting::query()->updateOrCreate(
                    ['setting_key' => self::MAESTRO_KEYS['token']],
                    ['setting_value' => $token]
                );
            }
        }
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, string|null>
     */
    private function values(array $keys): array
    {
        if (!Schema::hasTable('realtime_runtime_settings')) {
            return [];
        }

        return RealtimeRuntimeSetting::query()
            ->whereIn('setting_key', $keys)
            ->get()
            ->mapWithKeys(fn (RealtimeRuntimeSetting $setting) => [
                $setting->setting_key => $this->stringValue($setting->setting_value),
            ])
            ->all();
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function boolValue(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private function intValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number === false ? null : (int) $number;
    }

    private function intStringValue(mixed $value): ?string
    {
        $number = $this->intValue($value);

        return $number === null ? null : (string) $number;
    }

}
