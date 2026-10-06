<?php
// Prepared read-only effective-config/autoload guard. No kernel/provider boot or application requests.
$root = dirname(__DIR__);
try {
    if (PHP_VERSION !== '8.2.29' || PHP_INT_SIZE !== 8) { throw new RuntimeException('php_runtime_mismatch'); }
    if (is_file($root.'/.env') || is_file($root.'/bootstrap/cache/config.php')) { throw new RuntimeException('configuration_not_isolated'); }
    $values = ['APP_ENV'=>'testing','APP_KEY'=>'0123456789abcdef0123456789abcdef','CACHE_DRIVER'=>'array','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync','SESSION_DRIVER'=>'array','REALTIME_GATEWAY_TIMING_ENABLED'=>'false','REALTIME_EVENT_PUBLISH_TRACE'=>'false','REALTIME_TRANSPORT_OBSERVER_ENABLED'=>'false','REALTIME_EMBEDDED_MEDIA_CHUNK_DISPATCH_ENABLED'=>'false','MAESTRO_TELEMETRY_ENABLED'=>'false','MAESTRO_TELEMETRY_LOCAL_BYPASS_ENABLED'=>'false'];
    foreach ($values as $key=>$value) { putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    (new Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap($app);
    (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
    $config = $app->make('config');
    $expected = ['app.env'=>'testing','app.key'=>$values['APP_KEY'],'cache.default'=>'array','database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:','mail.default'=>'array','queue.default'=>'sync','session.driver'=>'array','realtime.gateway_timing_enabled'=>false,'realtime.event_publish_trace_enabled'=>false,'realtime.embedded_media_chunk_dispatch_enabled'=>false,'realtime.maestro_telemetry.enabled'=>false,'realtime.maestro_telemetry.local_bypass_enabled'=>false];
    foreach ($expected as $key=>$value) { if ($config->get($key) !== $value) { throw new RuntimeException('effective_config_mismatch'); } }
    // PR4 is separate; require absent/default-off observer if no config mapping exists.
    if ($config->get('realtime.transport_observer_enabled', false) !== false) { throw new RuntimeException('transport_enabled'); }
    $origins = [App\Realtime\Observability\RealtimeDiagnosticEmitter::class=>'app/Realtime/Observability/RealtimeDiagnosticEmitter.php',App\Realtime\Observability\RealtimeDiagnosticRecord::class=>'app/Realtime/Observability/RealtimeDiagnosticRecord.php',App\Realtime\Observability\RealtimeDiagnosticWriteResult::class=>'app/Realtime/Observability/RealtimeDiagnosticWriteResult.php',App\Realtime\Observability\RealtimeLoopLagDiagnostics::class=>'app/Realtime/Observability/RealtimeLoopLagDiagnostics.php',App\Realtime\Observability\RealtimeCallbackDiagnostics::class=>'app/Realtime/Observability/RealtimeCallbackDiagnostics.php'];
    foreach ($origins as $class=>$file) { if (realpath((new ReflectionClass($class))->getFileName()) !== realpath($root.'/'.$file)) { throw new RuntimeException('autoload_origin_mismatch'); } }
    foreach (['RealtimeDiagnosticEmitterTest','RealtimeDiagnosticRecordTest','RealtimeDiagnosticWriteResultTest','RealtimeLoopLagDiagnosticsTest','RealtimeCallbackDiagnosticsTest'] as $test) { if (realpath((new ReflectionClass('Tests\\Unit\\'.$test))->getFileName()) !== realpath($root.'/tests/Unit/'.$test.'.php')) { throw new RuntimeException('test_origin_mismatch'); } }
    echo json_encode(['offline_config_verified'=>true,'app_origins'=>5,'test_origins'=>5,'php_version'=>PHP_VERSION],JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Fixed output only; exception details may contain local/private configuration.
    fwrite(STDERR, "offline_configuration_or_autoload_guard_failed\n");
    exit(124);
}
