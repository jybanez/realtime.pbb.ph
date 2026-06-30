<?php

namespace App\Services\Account;

use App\Realtime\Settings\RealtimeRuntimeSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AccountClient
{
    public function __construct(private readonly RealtimeRuntimeSettings $settings)
    {
    }

    public function authorizationUrl(Request $request): string
    {
        $config = $this->ssoSettings();
        $state = bin2hex(random_bytes(16));
        $request->session()->put('pbb_account.state', $state);
        $request->session()->save();

        return rtrim((string) $config['base_url'], '/').'/oauth/authorize?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $config['scopes']),
            'state' => $state,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function handleCallback(Request $request, array $query): array
    {
        if (isset($query['error'])) {
            throw new RuntimeException((string) ($query['error_description'] ?? $query['error']));
        }

        $code = trim((string) ($query['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('Account callback is missing authorization code.');
        }

        $incomingState = (string) ($query['state'] ?? '');
        $expectedState = $request->session()->pull('pbb_account.state');
        $request->session()->save();

        if ($incomingState === '' || !is_string($expectedState) || !hash_equals($expectedState, $incomingState)) {
            throw new RuntimeException('Account callback state is invalid or expired.');
        }

        return $this->exchangeCode($code);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code): array
    {
        $config = $this->ssoSettings();
        $secret = trim((string) $config['client_secret']);
        if ($secret === '') {
            throw new RuntimeException('Account client secret is not configured.');
        }

        $response = $this->http()
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => $config['client_id'],
                'client_secret' => $secret,
                'redirect_uri' => $config['redirect_uri'],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response->json(), $response->status()));
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new RuntimeException('Account returned an invalid token response.');
        }

        return $this->identityFromTokenPayload($payload);
    }

    public function isReady(): bool
    {
        $config = $this->ssoSettings();

        try {
            $response = $this->http()
                ->acceptJson()
                ->get(rtrim((string) $config['base_url'], '/').'/up');
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && ($response->json('status') === 'ok');
    }

    public function logoutUrl(): string
    {
        $config = $this->ssoSettings();

        return rtrim((string) $config['base_url'], '/').'/oauth/logout?'.http_build_query([
            'client_id' => $config['client_id'],
            'post_logout_redirect_uri' => $config['post_logout_redirect_uri'] ?: url('/'),
        ]);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $config = $this->ssoSettings();
        $request = Http::timeout(max(1, (int) $config['timeout_seconds']));
        $caBundle = trim((string) $config['ca_bundle']);

        if ($caBundle !== '') {
            $request = $request->withOptions(['verify' => $caBundle]);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    public function ssoSettings(): array
    {
        return $this->settings->accountSso();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function identityFromTokenPayload(array $payload): array
    {
        $identity = $payload['identity'] ?? $payload['user'] ?? $payload;
        if (isset($identity['user']) && is_array($identity['user'])) {
            $identity = $identity['user'];
        }

        if (!is_array($identity)) {
            throw new RuntimeException('Account identity response is invalid.');
        }

        $pbbUserId = trim((string) ($identity['pbb_user_id'] ?? ''));
        if ($pbbUserId === '') {
            throw new RuntimeException('Account identity response is missing pbb_user_id.');
        }

        return [
            'pbb_user_id' => $pbbUserId,
            'name' => trim((string) ($identity['name'] ?? '')),
            'email' => $this->nullableString($identity['email'] ?? null),
            'mobile' => $this->nullableString($identity['mobile'] ?? null),
            'status' => $this->nullableString($identity['status'] ?? null),
            'raw' => $identity,
        ];
    }

    /**
     * @param mixed $payload
     */
    private function errorMessage(mixed $payload, int $status): string
    {
        if (is_array($payload)) {
            if (isset($payload['message']) && is_string($payload['message'])) {
                return $payload['message'];
            }

            if (isset($payload['error']) && is_string($payload['error'])) {
                return $payload['error'];
            }
        }

        return "Account request failed with HTTP {$status}.";
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
