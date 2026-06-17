<?php

declare(strict_types=1);

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use App\Realtime\Ingress\BackendIngressSecret;
use Illuminate\Contracts\Console\Kernel;

const REALTIME_POPULATOR_VERSION = '1.0.0';
const REALTIME_DEFAULT_SOURCE = 'resources/data/realtime/hotline-client-data.json';

function usage(): void
{
    fwrite(STDERR, "Usage: php tools/populate-initial-data.php --config <path> --report <path> [--dry-run] [--mode initial|repair|refresh|demo] [--verbose]\n");
}

function parse_args(array $argv): array
{
    $args = [
        'config' => null,
        'report' => null,
        'dry_run' => false,
        'mode' => null,
        'verbose' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        switch ($arg) {
            case '--config':
                $args['config'] = $argv[++$i] ?? null;
                break;
            case '--report':
                $args['report'] = $argv[++$i] ?? null;
                break;
            case '--dry-run':
                $args['dry_run'] = true;
                break;
            case '--mode':
                $args['mode'] = $argv[++$i] ?? null;
                break;
            case '--verbose':
                $args['verbose'] = true;
                break;
            case '--help':
            case '-h':
                usage();
                exit(0);
            default:
                fwrite(STDERR, "Unknown argument: {$arg}\n");
                usage();
                exit(2);
        }
    }

    return $args;
}

function read_json_file(string $path): array
{
    if (! is_file($path)) {
        throw new RuntimeException("File not found: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded)) {
        throw new RuntimeException("Invalid JSON file: {$path}");
    }

    return $decoded;
}

function write_report(string $path, array $report): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function report_base(array $config, string $mode, bool $dryRun, string $startedAt): array
{
    return [
        'schema_version' => 1,
        'app' => 'pbb-realtime',
        'tool' => 'populate_initial_data',
        'version' => REALTIME_POPULATOR_VERSION,
        'run_id' => (string) ($config['kit']['run_id'] ?? ''),
        'mode' => $mode,
        'dry_run' => $dryRun,
        'status' => 'running',
        'started_at' => $startedAt,
        'finished_at' => null,
        'summary' => '',
        'sources' => [],
        'results' => [],
        'warnings' => [],
        'errors' => [],
    ];
}

function finish_report(array $report, string $status, string $summary): array
{
    $report['status'] = $status;
    $report['summary'] = $summary;
    $report['finished_at'] = date(DATE_ATOM);

    return $report;
}

function string_value(mixed $value): ?string
{
    if (! is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value !== '' ? $value : null;
}

function default_source_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, REALTIME_DEFAULT_SOURCE);
}

function list_records(array $config): array
{
    $populate = $config['realtime']['populate'] ?? [];
    if (! is_array($populate)) {
        return [
            'records' => [],
            'source_path' => null,
            'source_id' => null,
            'source_status' => 'skipped',
            'used_default_source' => false,
        ];
    }

    $records = [];
    $sourcePath = null;
    $sourceId = null;
    $usedDefaultSource = false;

    if (isset($populate['source']) && is_string($populate['source']) && trim($populate['source']) !== '') {
        $sourcePath = trim($populate['source']);
        $sourceId = 'configured_population_source';
    }

    if ($sourcePath === null && empty($populate['clients']) && is_file(default_source_path())) {
        $sourcePath = default_source_path();
        $sourceId = 'packaged_pbb_client_data';
        $usedDefaultSource = true;
    }

    if ($sourcePath !== null) {
        $source = read_json_file($sourcePath);
        $records = $source['clients'] ?? $source['records'] ?? [];
    }

    if (isset($populate['clients']) && is_array($populate['clients'])) {
        $records = array_merge($records, $populate['clients']);
    }

    return [
        'records' => array_values(array_filter($records, 'is_array')),
        'source_path' => $sourcePath,
        'source_id' => $sourceId,
        'source_status' => $sourcePath === null ? 'skipped' : 'success',
        'used_default_source' => $usedDefaultSource,
    ];
}

function validate_client_record(array $record, int $index): array
{
    $errors = [];
    if (trim((string) ($record['name'] ?? '')) === '') {
        $errors[] = "clients[{$index}].name is required.";
    }

    foreach (['policies', 'projects'] as $key) {
        if (isset($record[$key]) && ! is_array($record[$key])) {
            $errors[] = "clients[{$index}].{$key} must be an array.";
        }
    }

    return $errors;
}

function attrs(array $record, array $allowed): array
{
    $out = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $record)) {
            $out[$key] = $record[$key];
        }
    }

    return $out;
}

function normalize_media_ingest_settings(mixed $settings): mixed
{
    if (! is_array($settings)) {
        return $settings;
    }

    $caBundle = string_value($settings['ca_bundle'] ?? null)
        ?? string_value($settings['curl_ca_bundle'] ?? null)
        ?? string_value($settings['ssl_cert_file'] ?? null);

    unset($settings['curl_ca_bundle'], $settings['ssl_cert_file']);

    if ($caBundle !== null) {
        $settings['ca_bundle'] = $caBundle;
    }

    return $settings;
}

function normalize_project_record(array $record): array
{
    if (array_key_exists('media_ingest_settings', $record)) {
        $record['media_ingest_settings'] = normalize_media_ingest_settings($record['media_ingest_settings']);
    }

    return $record;
}

function find_client(array $record): ?RealtimeClient
{
    $code = trim((string) ($record['client_code'] ?? ''));
    if ($code !== '') {
        return RealtimeClient::query()->where('client_code', $code)->first();
    }

    return RealtimeClient::query()->where('name', (string) $record['name'])->first();
}

function upsert_client(array $record, bool $dryRun, bool $overwriteSecrets): array
{
    $existing = find_client($record);
    $operation = $existing ? 'updated' : 'inserted';

    if ($dryRun) {
        return ['operation' => $existing ? 'would_update' : 'would_insert', 'id' => $existing?->id, 'client_code' => $existing?->client_code ?? $record['client_code'] ?? null];
    }

    $client = $existing ?: new RealtimeClient();
    if (! $existing && ! empty($record['client_code'])) {
        $client->client_code = (string) $record['client_code'];
    }
    if (! $existing && ! empty($record['project_code'])) {
        $client->project_code = (string) $record['project_code'];
    }

    $client->fill(attrs($record, [
        'name',
        'status',
        'description',
        'integration_owner',
        'integration_notes',
        'issuer_identity',
        'token_issuance_mode',
        'trusted_signing_profile',
        'trust_notes',
        'allowed_origins',
        'origin_policy_mode',
        'policy_profile_code',
        'capability_profile_code',
        'room_policy_profile_code',
    ]));

    $secret = trim((string) ($record['backend_ingress_secret'] ?? ''));
    if ($secret !== '' && (! $existing || $overwriteSecrets || empty($client->backend_ingress_secret_digest))) {
        $client->forceFill(BackendIngressSecret::attributesForStorage($secret));
    }

    $client->save();

    return ['operation' => $operation, 'id' => $client->id, 'client_code' => $client->client_code];
}

function find_policy(RealtimeClient $client, array $record): ?RealtimePolicy
{
    $code = trim((string) ($record['policy_code'] ?? ''));
    if ($code !== '') {
        return RealtimePolicy::query()->where('policy_code', $code)->first();
    }

    return RealtimePolicy::query()
        ->where('client_id', $client->id)
        ->where('name', (string) ($record['name'] ?? ''))
        ->first();
}

function upsert_policy(RealtimeClient $client, array $record, bool $dryRun): array
{
    $existing = find_policy($client, $record);
    $operation = $existing ? 'updated' : 'inserted';

    if ($dryRun) {
        return ['operation' => $existing ? 'would_update' : 'would_insert', 'id' => $existing?->id, 'policy_code' => $existing?->policy_code ?? $record['policy_code'] ?? null];
    }

    $policy = $existing ?: new RealtimePolicy();
    if (! $existing && ! empty($record['policy_code'])) {
        $policy->policy_code = (string) $record['policy_code'];
    }
    $policy->client_id = $client->id;
    $policy->fill(attrs($record, [
        'name',
        'status',
        'description',
        'policy_category',
        'owner_team',
        'capability_profile',
        'room_policy_profile',
        'rate_limit_profile',
        'session_limit_profile',
        'allow_deny_mode',
    ]));
    $policy->save();

    return ['operation' => $operation, 'id' => $policy->id, 'policy_code' => $policy->policy_code];
}

function find_project(RealtimeClient $client, array $record): ?RealtimeProject
{
    $code = trim((string) ($record['project_code'] ?? ''));
    if ($code !== '') {
        return RealtimeProject::query()->where('project_code', $code)->first();
    }

    return RealtimeProject::query()
        ->where('client_id', $client->id)
        ->where('name', (string) ($record['name'] ?? ''))
        ->first();
}

function upsert_project(RealtimeClient $client, array $record, bool $dryRun): array
{
    $record = normalize_project_record($record);
    $existing = find_project($client, $record);
    $operation = $existing ? 'updated' : 'inserted';

    if ($dryRun) {
        return ['operation' => $existing ? 'would_update' : 'would_insert', 'id' => $existing?->id, 'project_code' => $existing?->project_code ?? $record['project_code'] ?? null];
    }

    $project = $existing ?: new RealtimeProject();
    if (! $existing && ! empty($record['project_code'])) {
        $project->project_code = (string) $record['project_code'];
    }
    $project->client_id = $client->id;
    $project->fill(attrs($record, [
        'name',
        'status',
        'description',
        'scope_notes',
        'allowed_origins',
        'media_ingest_settings',
        'product_query_forwarding_settings',
        'origin_policy_mode',
        'policy_profile_code',
        'capability_profile_code',
        'room_policy_profile_code',
    ]));
    $project->save();

    return ['operation' => $operation, 'id' => $project->id, 'project_code' => $project->project_code];
}

function increment_result(array &$result, string $operation): void
{
    if ($operation === 'would_insert') {
        $result['planned_inserted']++;
        return;
    }

    if ($operation === 'would_update') {
        $result['planned_updated']++;
        return;
    }

    if (array_key_exists($operation, $result)) {
        $result[$operation]++;
        return;
    }

    $result['failed']++;
}

$args = parse_args($argv);
$startedAt = date(DATE_ATOM);
$configPath = is_string($args['config']) ? $args['config'] : '';
$reportPath = is_string($args['report']) ? $args['report'] : '';

if ($configPath === '' || $reportPath === '') {
    usage();
    exit(2);
}

try {
    $config = read_json_file($configPath);
    $mode = (string) ($args['mode'] ?: $config['mode'] ?? 'initial');
    $dryRun = (bool) $args['dry_run'];
    $report = report_base($config, $mode, $dryRun, $startedAt);
    $populate = is_array($config['realtime']['populate'] ?? null) ? $config['realtime']['populate'] : [];
    $enabled = (bool) ($populate['enabled'] ?? true);

    if (! $enabled) {
        $report = finish_report($report, 'skipped', 'Realtime initial data population is disabled.');
        write_report($reportPath, $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $recordSource = list_records($config);
    $records = $recordSource['records'];
    $sourcePath = is_string($recordSource['source_path']) ? $recordSource['source_path'] : '';
    if ($sourcePath !== '') {
        $report['sources'][] = [
            'id' => $recordSource['source_id'] ?? 'realtime_population_source',
            'path' => $sourcePath,
            'status' => $recordSource['source_status'] ?? (is_file($sourcePath) ? 'success' : 'failed'),
            'used_default_source' => (bool) ($recordSource['used_default_source'] ?? false),
        ];
    }

    $validationErrors = [];
    foreach ($records as $index => $record) {
        $validationErrors = array_merge($validationErrors, validate_client_record($record, $index));
    }

    if ($validationErrors !== []) {
        foreach ($validationErrors as $error) {
            $report['errors'][] = ['id' => 'validation', 'message' => $error];
        }
        $report = finish_report($report, 'failed', 'Realtime population config failed validation.');
        write_report($reportPath, $report);
        exit(2);
    }

    require dirname(__DIR__) . '/vendor/autoload.php';
    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $overwriteSecrets = (bool) ($populate['options']['overwrite_secrets'] ?? false);
    $results = [
        'clients' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'planned_inserted' => 0, 'planned_updated' => 0],
        'policies' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'planned_inserted' => 0, 'planned_updated' => 0],
        'projects' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'planned_inserted' => 0, 'planned_updated' => 0],
    ];

    foreach ($records as $record) {
        $clientResult = upsert_client($record, $dryRun, $overwriteSecrets);
        increment_result($results['clients'], (string) $clientResult['operation']);

        $client = $dryRun
            ? (find_client($record) ?: new RealtimeClient(['name' => $record['name']]))
            : RealtimeClient::query()->where('client_code', $clientResult['client_code'])->firstOrFail();

        foreach (($record['policies'] ?? []) as $policyRecord) {
            if (! is_array($policyRecord) || trim((string) ($policyRecord['name'] ?? '')) === '') {
                $results['policies']['failed']++;
                continue;
            }
            $policyResult = upsert_policy($client, $policyRecord, $dryRun);
            increment_result($results['policies'], (string) $policyResult['operation']);
        }

        foreach (($record['projects'] ?? []) as $projectRecord) {
            if (! is_array($projectRecord) || trim((string) ($projectRecord['name'] ?? '')) === '') {
                $results['projects']['failed']++;
                continue;
            }
            $projectResult = upsert_project($client, $projectRecord, $dryRun);
            increment_result($results['projects'], (string) $projectResult['operation']);
        }
    }

    foreach ($results as $id => $result) {
        $report['results'][] = array_merge(['id' => $id], $result);
    }

    $report = finish_report(
        $report,
        array_sum(array_column($results, 'failed')) > 0 ? 'warning' : 'success',
        $dryRun ? 'Realtime initial data population dry run completed.' : 'Realtime initial data population completed.'
    );

    write_report($reportPath, $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'success' ? 0 : 1);
} catch (Throwable $e) {
    $config = isset($config) && is_array($config) ? $config : [];
    $report = report_base($config, (string) ($args['mode'] ?? 'initial'), (bool) $args['dry_run'], $startedAt);
    $report['errors'][] = ['id' => 'exception', 'message' => $e->getMessage()];
    $report = finish_report($report, 'failed', 'Realtime initial data population failed.');
    if ($reportPath !== '') {
        write_report($reportPath, $report);
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
