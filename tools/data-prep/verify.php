<?php

declare(strict_types=1);

use App\Models\RealtimeClient;
use App\Models\RealtimePolicy;
use App\Models\RealtimeProject;
use Illuminate\Contracts\Console\Kernel;

const REALTIME_DATA_PREP_VERIFY_VERSION = '1.0.0';
const REALTIME_DATA_PREP_DEFAULT_SOURCE = 'resources/data/realtime/hotline-client-data.json';

function usage(): void
{
    fwrite(STDERR, "Usage: php tools/data-prep/verify.php --config <path> --report <path> [--dry-run] [--mode initial|repair|refresh|demo] [--verbose]\n");
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
        'tool' => 'data_prep_verify',
        'version' => REALTIME_DATA_PREP_VERIFY_VERSION,
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

function default_source_path(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, REALTIME_DATA_PREP_DEFAULT_SOURCE);
}

function configured_source_path(array $config): string
{
    $verify = $config['realtime']['data_prep']['verify'] ?? [];
    if (is_array($verify) && isset($verify['source']) && is_string($verify['source']) && trim($verify['source']) !== '') {
        return trim($verify['source']);
    }

    $populate = $config['realtime']['populate'] ?? [];
    if (is_array($populate) && isset($populate['source']) && is_string($populate['source']) && trim($populate['source']) !== '') {
        return trim($populate['source']);
    }

    return default_source_path();
}

function expected_records(array $source): array
{
    return array_values(array_filter($source['clients'] ?? $source['records'] ?? [], 'is_array'));
}

function add_result(array &$report, string $id, string $type, int $expected, int $found, array $missing): void
{
    $failed = count($missing);
    $report['results'][] = [
        'id' => $id,
        'type' => $type,
        'action' => 'verify',
        'status' => $failed > 0 ? 'failed' : 'success',
        'expected' => $expected,
        'found' => $found,
        'missing' => $failed,
        'failed' => $failed,
        'missing_codes' => $missing,
    ];
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

    $sourcePath = configured_source_path($config);
    $source = read_json_file($sourcePath);
    $records = expected_records($source);
    $report['sources'][] = [
        'id' => realpath($sourcePath) === realpath(default_source_path()) ? 'packaged_hotline_client_data' : 'configured_data_prep_verify_source',
        'path' => $sourcePath,
        'status' => 'success',
        'used_default_source' => realpath($sourcePath) === realpath(default_source_path()),
    ];

    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $clientCodes = [];
    $policyCodes = [];
    $projectCodes = [];

    foreach ($records as $record) {
        $clientCode = trim((string) ($record['client_code'] ?? ''));
        if ($clientCode !== '') {
            $clientCodes[] = $clientCode;
        }

        foreach (($record['policies'] ?? []) as $policy) {
            if (is_array($policy) && trim((string) ($policy['policy_code'] ?? '')) !== '') {
                $policyCodes[] = trim((string) $policy['policy_code']);
            }
        }

        foreach (($record['projects'] ?? []) as $project) {
            if (is_array($project) && trim((string) ($project['project_code'] ?? '')) !== '') {
                $projectCodes[] = trim((string) $project['project_code']);
            }
        }
    }

    $foundClientCodes = RealtimeClient::query()
        ->whereIn('client_code', $clientCodes)
        ->pluck('client_code')
        ->all();
    $foundPolicyCodes = RealtimePolicy::query()
        ->whereIn('policy_code', $policyCodes)
        ->pluck('policy_code')
        ->all();
    $foundProjectCodes = RealtimeProject::query()
        ->whereIn('project_code', $projectCodes)
        ->pluck('project_code')
        ->all();

    add_result($report, 'hotline_clients', 'client_profile', count($clientCodes), count($foundClientCodes), array_values(array_diff($clientCodes, $foundClientCodes)));
    add_result($report, 'hotline_policies', 'policy_profile', count($policyCodes), count($foundPolicyCodes), array_values(array_diff($policyCodes, $foundPolicyCodes)));
    add_result($report, 'hotline_projects', 'project_scope', count($projectCodes), count($foundProjectCodes), array_values(array_diff($projectCodes, $foundProjectCodes)));

    $failed = array_sum(array_column($report['results'], 'failed'));
    $report = finish_report(
        $report,
        $failed > 0 ? 'failed' : 'success',
        $failed > 0 ? 'Realtime Data Prep verification found missing Hotline records.' : 'Realtime Data Prep verification passed for Hotline client data.'
    );

    write_report($reportPath, $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'success' ? 0 : 1);
} catch (Throwable $e) {
    $config = isset($config) && is_array($config) ? $config : [];
    $report = report_base($config, (string) ($args['mode'] ?? 'initial'), (bool) $args['dry_run'], $startedAt);
    $report['errors'][] = ['id' => 'exception', 'message' => $e->getMessage()];
    $report = finish_report($report, 'failed', 'Realtime Data Prep verification failed.');
    if ($reportPath !== '') {
        write_report($reportPath, $report);
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
