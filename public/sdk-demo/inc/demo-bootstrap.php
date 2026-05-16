<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sdk/php/pbb_realtime_backend_sdk.php';

function demoEnv(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }

    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : $default;
}

function demoRealtimeConfig(): RealtimeConfig
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'wss' : 'ws';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $trustedIssuers = array_values(array_filter(array_map(
        'trim',
        explode(',', demoEnv('REALTIME_TRUSTED_ISSUERS', 'local.pbb.test'))
    )));
    $issuer = $trustedIssuers[0] ?? 'local.pbb.test';

    return new RealtimeConfig([
        'issuer' => $issuer,
        'audience' => demoEnv('REALTIME_TOKEN_AUDIENCE', 'pbb-realtime'),
        'signing_secret' => demoEnv('REALTIME_TOKEN_SIGNING_SECRET', 'local-realtime-dev-secret'),
        'websocket_url' => demoEnv('REALTIME_PUBLIC_WEBSOCKET_URL', sprintf('%s://%s/realtime', $scheme, $host)),
        'token_ttl_seconds' => 3600,
    ]);
}

/**
 * @return array<string, mixed>
 */
function demoJsonInput(): array
{
    return json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
}

function demoJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
