<?php

namespace App\Http\Middleware;

use App\Realtime\Settings\RealtimeRuntimeSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyAccountAdminService
{
    public function __construct(private readonly RealtimeRuntimeSettings $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->settings->accountAdmin();

        if (!$config['enabled']) {
            return $this->fail('account_admin_disabled', 'Account admin API is disabled.', 503);
        }

        $configuredClient = trim((string) $config['client']);
        $providedClient = trim((string) $request->header('X-PBB-Account-Client'));
        if ($configuredClient === '' || $providedClient !== $configuredClient) {
            return $this->fail('invalid_account_client', 'The Account client header is missing or invalid.', 401);
        }

        $configuredToken = trim((string) $config['token']);
        $providedToken = trim((string) $request->bearerToken());
        if ($providedToken === '') {
            $providedToken = trim((string) $request->header('X-PBB-Account-Admin-Token'));
        }

        if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
            Log::warning('Realtime account-admin token rejected', [
                'configured_enabled' => $config['enabled'],
                'configured_client' => $configuredClient,
                'provided_client' => $providedClient,
                'configured_token_length' => strlen($configuredToken),
                'configured_token_sha12' => $configuredToken !== '' ? substr(hash('sha256', $configuredToken), 0, 12) : null,
                'provided_token_length' => strlen($providedToken),
                'provided_token_sha12' => $providedToken !== '' ? substr(hash('sha256', $providedToken), 0, 12) : null,
            ]);

            return $this->fail('invalid_app_admin_token', 'The app-admin token is missing or invalid.', 401);
        }

        return $next($request);
    }

    private function fail(string $code, string $message, int $status): Response
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $code,
            ],
        ], $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
