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

    public function updateAccountIntegration(Request $request, RealtimeRuntimeSettings $settings): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && method_exists($user, 'isAdmin') && $user->isAdmin(), 403, 'Admin access required.');

        $validated = $request->validate([
            'sso.enabled' => ['required', 'boolean'],
            'sso.base_url' => ['required', 'url', 'max:255'],
            'sso.client_id' => ['required', 'string', 'max:120'],
            'sso.client_secret' => ['nullable', 'string', 'max:4096'],
            'sso.redirect_uri' => ['required', 'url', 'max:255'],
            'sso.post_logout_redirect_uri' => ['required', 'url', 'max:255'],
            'sso.scopes' => ['required', 'string', 'max:255'],
            'sso.timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'sso.ca_bundle' => ['nullable', 'string', 'max:500'],
            'app_admin.enabled' => ['required', 'boolean'],
            'app_admin.client' => ['required', 'string', 'max:120'],
            'app_admin.token' => ['nullable', 'string', 'max:4096'],
        ]);

        $settings->updateAccountIntegration($validated);

        return response()->json([
            'status' => true,
            'data' => [
                'account' => [
                    'sso' => $this->accountSsoSettingsForResponse($settings),
                    'app_admin' => $this->accountAdminSettingsForResponse($settings),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountSsoSettingsForResponse(RealtimeRuntimeSettings $settings): array
    {
        $sso = $settings->accountSso();
        unset($sso['client_secret']);

        return $sso;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountAdminSettingsForResponse(RealtimeRuntimeSettings $settings): array
    {
        $accountAdmin = $settings->accountAdmin();

        return [
            'enabled' => (bool) $accountAdmin['enabled'],
            'client' => (string) $accountAdmin['client'],
            'token_configured' => (string) $accountAdmin['token'] !== '',
        ];
    }
}
