<?php

declare(strict_types=1);

use App\Realtime\Settings\RealtimeRuntimeSettings;
use Illuminate\Contracts\Console\Kernel;

const REALTIME_DATA_PREP_APPLY_SETTINGS_VERSION = '1.0.0';

function usage(): void
{
    fwrite(STDERR, "Usage: php tools/data-prep/apply-settings.php --config <path> --report <path> [--dry-run] [--mode initial|repair|refresh|demo] [--verbose]\n");
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
        'tool' => 'data_prep_apply_settings',
        'version' => REALTIME_DATA_PREP_APPLY_SETTINGS_VERSION,
        'run_id' => (string) ($config['kit']['run_id'] ?? ''),
        'mode' => $mode,
        'dry_run' => $dryRun,
        'status' => 'running',
        'started_at' => $startedAt,
        'finished_at' => null,
        'summary' => '',
        'sources' => [],
        'results' => [],
        'outputs' => [],
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

function bool_value(mixed $value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
}

function int_value(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = filter_var($value, FILTER_VALIDATE_INT);

    return $number === false ? null : (int) $number;
}

function maestro_config(array $config): array
{
    $apply = $config['realtime']['data_prep']['apply_settings']['maestro'] ?? [];
    if (! is_array($apply)) {
        $apply = [];
    }

    $secrets = $config['secrets']['values'] ?? [];
    if (! is_array($secrets)) {
        $secrets = [];
    }

    $token = string_value($apply['telemetry_token'] ?? null)
        ?? string_value($apply['token'] ?? null)
        ?? string_value($secrets['maestro_realtime_telemetry_token'] ?? null)
        ?? string_value($secrets['realtime_maestro_telemetry_token'] ?? null);

    return [
        'enabled' => bool_value($apply['enabled'] ?? null) ?? ($token !== null),
        'base_url' => string_value($apply['base_url'] ?? null),
        'app_code' => string_value($apply['app_code'] ?? null) ?? 'realtime',
        'token' => $token,
        'connect_timeout_seconds' => int_value($apply['connect_timeout_seconds'] ?? null) ?? 3,
        'timeout_seconds' => int_value($apply['timeout_seconds'] ?? null) ?? 5,
        'tls_verify' => bool_value($apply['tls_verify'] ?? ($apply['verify_tls'] ?? null)) ?? true,
    ];
}

function validate_maestro_config(array $maestro): array
{
    $errors = [];

    if (($maestro['enabled'] ?? false) === true) {
        if (string_value($maestro['base_url'] ?? null) === null) {
            $errors[] = 'realtime.data_prep.apply_settings.maestro.base_url is required when Maestro telemetry is enabled.';
        }
        if (string_value($maestro['app_code'] ?? null) === null) {
            $errors[] = 'realtime.data_prep.apply_settings.maestro.app_code is required when Maestro telemetry is enabled.';
        }
        if (string_value($maestro['token'] ?? null) === null) {
            $errors[] = 'realtime.data_prep.apply_settings.maestro.telemetry_token is required when Maestro telemetry is enabled.';
        }
    }

    foreach (['connect_timeout_seconds', 'timeout_seconds'] as $key) {
        $value = int_value($maestro[$key] ?? null);
        if ($value === null || $value < 1) {
            $errors[] = "realtime.data_prep.apply_settings.maestro.{$key} must be a positive integer.";
        }
    }

    return $errors;
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
    $maestro = maestro_config($config);

    $validationErrors = validate_maestro_config($maestro);
    foreach ($validationErrors as $error) {
        $report['errors'][] = ['id' => 'validation', 'message' => $error];
    }

    if ($validationErrors !== []) {
        $report['results'][] = [
            'id' => 'maestro_telemetry_settings',
            'type' => 'runtime_settings',
            'action' => $dryRun ? 'plan' : 'apply',
            'status' => 'failed',
            'failed' => count($validationErrors),
            'token_supplied' => string_value($maestro['token'] ?? null) !== null,
        ];
        $report = finish_report($report, 'failed', 'Realtime Data Prep settings failed validation.');
        write_report($reportPath, $report);
        exit(2);
    }

    $settingsPayload = [
        'enabled' => (bool) $maestro['enabled'],
        'base_url' => string_value($maestro['base_url'] ?? null),
        'app_code' => string_value($maestro['app_code'] ?? null) ?? 'realtime',
        'connect_timeout_seconds' => (int) $maestro['connect_timeout_seconds'],
        'timeout_seconds' => (int) $maestro['timeout_seconds'],
    ];

    if (string_value($maestro['token'] ?? null) !== null) {
        $settingsPayload['token'] = string_value($maestro['token']);
    }

    if (! $dryRun) {
        require dirname(__DIR__, 2) . '/vendor/autoload.php';
        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();

        $app->make(RealtimeRuntimeSettings::class)->updateMaestroTelemetry($settingsPayload);
    }

    $report['results'][] = [
        'id' => 'maestro_telemetry_settings',
        'type' => 'runtime_settings',
        'action' => $dryRun ? 'plan_update' : 'update',
        'status' => 'success',
        'updated' => $dryRun ? 0 : 1,
        'planned_updated' => $dryRun ? 1 : 0,
        'failed' => 0,
        'settings' => [
            'enabled' => (bool) $maestro['enabled'],
            'base_url' => string_value($maestro['base_url'] ?? null),
            'app_code' => string_value($maestro['app_code'] ?? null) ?? 'realtime',
            'connect_timeout_seconds' => (int) $maestro['connect_timeout_seconds'],
            'timeout_seconds' => (int) $maestro['timeout_seconds'],
            'tls_verify' => (bool) $maestro['tls_verify'],
            'token_supplied' => string_value($maestro['token'] ?? null) !== null,
        ],
    ];

    $report['outputs'][] = [
        'id' => 'realtime_maestro_telemetry',
        'kind' => 'runtime_settings',
        'target_app' => 'pbb-realtime',
        'status' => $dryRun ? 'planned' : 'applied',
        'secret_refs' => ['runtime.realtime.maestro_telemetry_token'],
        'token_supplied' => string_value($maestro['token'] ?? null) !== null,
    ];

    $report = finish_report(
        $report,
        'success',
        $dryRun ? 'Realtime Data Prep settings dry run completed.' : 'Realtime Data Prep settings applied.'
    );

    write_report($reportPath, $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $config = isset($config) && is_array($config) ? $config : [];
    $report = report_base($config, (string) ($args['mode'] ?? 'initial'), (bool) $args['dry_run'], $startedAt);
    $report['errors'][] = ['id' => 'exception', 'message' => $e->getMessage()];
    $report = finish_report($report, 'failed', 'Realtime Data Prep settings failed.');
    if ($reportPath !== '') {
        write_report($reportPath, $report);
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
