<?php

declare(strict_types=1);

use App\Models\RealtimeClient;
use App\Models\RealtimeProject;
use App\Realtime\Ingress\BackendIngressSecret;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
chdir($root);

require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$app = require $root . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
$app->make(Kernel::class)->bootstrap();

$action = (string) ($argv[1] ?? '');
$capability = (string) ($argv[2] ?? '');
$context = json_decode((string) (getenv('PBB_COMMISSIONING_CONTEXT') ?: '{}'), true);

if (! is_array($context)) {
    respond(['status' => 'failed', 'message' => 'Invalid commissioning context.']);
}

try {
    match ($action) {
        'provision' => provision_backend_ingress($context, $capability),
        'verify-provider' => verify_backend_ingress($context, $capability),
        default => respond(['status' => 'failed', 'message' => 'Unsupported Realtime commissioning action.']),
    };
} catch (Throwable $error) {
    respond([
        'status' => 'failed',
        'message' => preg_replace('/[[:cntrl:]]+/', ' ', $error->getMessage()) ?: 'Realtime commissioning adapter failed.',
    ]);
}

function provision_backend_ingress(array $context, string $capability): never
{
    if ($capability !== 'realtime.backend-ingress') {
        respond(['status' => 'failed', 'message' => 'Unsupported Realtime provider capability.']);
    }

    [$client, $projectCode] = resolve_client_scope($context);
    $secret = bin2hex(random_bytes(32));
    $client->forceFill(BackendIngressSecret::attributesForStorage($secret));
    $client->save();

    respond([
        'status' => 'success',
        'credential_reference' => credential_reference($client->client_code, $projectCode),
        'secret' => $secret,
        'identifiers' => array_filter([
            'client_code' => $client->client_code,
            'project_code' => $projectCode,
        ]),
    ]);
}

function verify_backend_ingress(array $context, string $capability): never
{
    if ($capability !== 'realtime.backend-ingress') {
        respond(['status' => 'failed', 'message' => 'Unsupported Realtime provider capability.']);
    }

    [$client, $projectCode] = resolve_client_scope($context);
    $hasSecret = trim((string) $client->backend_ingress_secret_digest) !== '';

    respond([
        'status' => $hasSecret ? 'success' : 'failed',
        'credential_reference' => credential_reference($client->client_code, $projectCode),
        'identifiers' => array_filter([
            'client_code' => $client->client_code,
            'project_code' => $projectCode,
        ]),
    ]);
}

/**
 * @return array{0: RealtimeClient, 1: string|null}
 */
function resolve_client_scope(array $context): array
{
    $clientCode = first_context_value($context, [
        'client_code',
        'realtime_client_code',
        'realtime.backend_ingress.client_code',
    ]);
    $projectCode = first_context_value($context, [
        'project_code',
        'realtime_project_code',
        'realtime.backend_ingress.project_code',
    ]);

    if ($clientCode === '') {
        respond(['status' => 'failed', 'message' => 'Commissioning context is missing client_code.']);
    }

    $client = RealtimeClient::query()->where('client_code', $clientCode)->first();
    if (! $client instanceof RealtimeClient) {
        respond(['status' => 'failed', 'message' => 'Realtime client_code is not registered.']);
    }

    if ($projectCode !== '') {
        $project = RealtimeProject::query()
            ->where('project_code', $projectCode)
            ->where('client_id', $client->id)
            ->first();

        if (! $project instanceof RealtimeProject) {
            respond(['status' => 'failed', 'message' => 'Realtime project_code is not registered for client_code.']);
        }
    }

    return [$client, $projectCode === '' ? null : $projectCode];
}

/**
 * @param list<string> $keys
 */
function first_context_value(array $context, array $keys): string
{
    foreach ($keys as $key) {
        $value = dotted_value($context, $key);
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    foreach ($context as $value) {
        if (! is_array($value)) {
            continue;
        }

        $found = first_context_value($value, $keys);
        if ($found !== '') {
            return $found;
        }
    }

    return '';
}

function dotted_value(array $source, string $path): mixed
{
    $value = $source;
    foreach (explode('.', $path) as $part) {
        if (! is_array($value) || ! array_key_exists($part, $value)) {
            return null;
        }

        $value = $value[$part];
    }

    return $value;
}

function credential_reference(string $clientCode, ?string $projectCode): string
{
    return 'pbb-realtime:backend-ingress:' . $clientCode . ($projectCode ? ':' . $projectCode : '');
}

function respond(array $payload): never
{
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}
