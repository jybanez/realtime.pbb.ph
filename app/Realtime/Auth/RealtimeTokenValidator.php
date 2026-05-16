<?php

namespace App\Realtime\Auth;

use App\Models\RealtimeClient;
use Firebase\JWT\JWT;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\Key;
use JsonException;
use Throwable;

class RealtimeTokenValidator
{
    public function validate(string $token): RealtimeTokenClaims
    {
        $secret = (string) config('realtime.token_signing_secret');

        if ($secret === '') {
            throw new RealtimeTokenValidationException(
                'missing-signing-secret',
                'Realtime token signing secret is not configured.'
            );
        }

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (ExpiredException $e) {
            throw new RealtimeTokenValidationException(
                'expired-token',
                'The realtime token is expired.'
            );
        } catch (Throwable $e) {
            throw new RealtimeTokenValidationException(
                'invalid-token',
                'Unable to verify the realtime token signature.'
            );
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(
                json_encode($decoded, JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RealtimeTokenValidationException(
                'invalid-token',
                'Unable to normalize the realtime token payload.'
            );
        }

        $expectedAudience = (string) config('realtime.token_audience');

        if (!isset($payload['aud']) || !is_string($payload['aud']) || $payload['aud'] !== $expectedAudience) {
            throw new RealtimeTokenValidationException(
                'invalid-audience',
                'The realtime token audience is not valid.'
            );
        }

        if (!isset($payload['exp']) || !is_numeric($payload['exp']) || (int) $payload['exp'] <= time()) {
            throw new RealtimeTokenValidationException(
                'expired-token',
                'The realtime token is expired.'
            );
        }

        $claims = RealtimeTokenClaims::fromArray($payload);

        $this->assertTrustedIssuer($claims);

        return $claims;
    }

    private function assertTrustedIssuer(RealtimeTokenClaims $claims): void
    {
        $client = RealtimeClient::query()
            ->where('client_code', $claims->appCode)
            ->first();

        if ($client instanceof RealtimeClient) {
            $trustedClientIssuers = array_values(array_filter([
                $this->normalizeIssuerValue($client->issuer_identity),
                $this->normalizeIssuerValue($client->trusted_signing_profile),
            ]));

            if ($trustedClientIssuers !== [] && !in_array($claims->issuer, $trustedClientIssuers, true)) {
                throw new RealtimeTokenValidationException(
                    'invalid-issuer',
                    'The realtime token issuer is not trusted for the target client.'
                );
            }

            return;
        }

        $trustedIssuers = array_values(array_filter(array_map(
            fn ($value) => $this->normalizeIssuerValue($value),
            is_array(config('realtime.trusted_issuers', [])) ? config('realtime.trusted_issuers', []) : []
        )));

        if ($trustedIssuers !== [] && !in_array($claims->issuer, $trustedIssuers, true)) {
            throw new RealtimeTokenValidationException(
                'invalid-issuer',
                'The realtime token issuer is not trusted.'
            );
        }
    }

    private function normalizeIssuerValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
