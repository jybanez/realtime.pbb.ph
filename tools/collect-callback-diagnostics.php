<?php

// Reviewed preparation only. Never launched by the gateway. Usage: php <script> <new-output-file> [port=9998] [seconds=30].
// Collector disk can stall independently; volume/time checks do not bound filesystem syscall latency.
require dirname(__DIR__).'/vendor/autoload.php';

$output = $argv[1] ?? '';
$port = filter_var($argv[2] ?? '9998', FILTER_VALIDATE_INT);
$seconds = filter_var($argv[3] ?? '30', FILTER_VALIDATE_INT);
if ($output === '' || $port === false || $port < 1 || $port > 65535 || $seconds === false || $seconds < 1 || $seconds > 300) {
    fwrite(STDERR, "Expected new output path, port1..65535, duration1..300 seconds.\n"); exit(2);
}
$file = @fopen($output, 'x'); // Never overwrite existing evidence.
if ($file === false) { fwrite(STDERR, "Cannot create output file.\n"); exit(2); }
$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($socket === false || !@socket_bind($socket, '127.0.0.1', $port) || !socket_set_nonblock($socket)) {
    fclose($file); fwrite(STDERR, "Cannot bind nonblocking loopback receiver.\n"); exit(2);
}
$deadline = hrtime(true) + $seconds * 1000000000;
$captureId = bin2hex(random_bytes(16));
fwrite(STDOUT, json_encode(['state' => 'receiver.ready', 'capture_id' => $captureId, 'pid' => getmypid(), 'port' => $port], JSON_THROW_ON_ERROR).PHP_EOL);
$stats = ['capture_id' => $captureId, 'pid' => getmypid(), 'received' => 0, 'accepted' => 0, 'rejected' => 0, 'oversized' => 0, 'receive_errors' => 0, 'idle_polls' => 0, 'received_bytes' => 0, 'written_bytes' => 0, 'write_failures' => 0, 'partial_records' => 0, 'terminal_reason' => 'deadline_or_budget', 'kernel_or_sender_loss' => 'unknown'];
try {
    while (hrtime(true) < $deadline && $stats['received'] < 4096 && $stats['received_bytes'] < 4194304) {
        $data = ''; $peer = ''; $peerPort = 0;
        $length = @socket_recvfrom($socket, $data, 2049, 0, $peer, $peerPort);
        if ($length === false) {
            $error = socket_last_error($socket);
            $idleErrors = [];
            foreach (['SOCKET_EAGAIN', 'SOCKET_EWOULDBLOCK'] as $constant) { if (defined($constant)) { $idleErrors[] = constant($constant); } }
            socket_clear_error($socket);
            if (in_array($error, $idleErrors, true)) { ++$stats['idle_polls']; usleep(10000); continue; }
            ++$stats['receive_errors'];
            if (defined('SOCKET_EMSGSIZE') && $error === SOCKET_EMSGSIZE) { ++$stats['oversized']; }
            $stats['terminal_reason'] = 'receive_error'; break;
        }
        ++$stats['received']; $stats['received_bytes'] += $length;
        if ($length > 2048) { ++$stats['oversized']; ++$stats['rejected']; continue; }
        if ($peer !== '127.0.0.1' || !\App\Realtime\Observability\RealtimeDiagnosticRecord::valid($data)) { ++$stats['rejected']; continue; }
        $line = $data.PHP_EOL;
        if ($stats['written_bytes'] + strlen($line) > 4194304) { ++$stats['rejected']; $stats['terminal_reason'] = 'output_budget'; break; }
        $written = fwrite($file, $line);
        $stats['written_bytes'] += $written === false ? 0 : $written;
        if ($written !== strlen($line)) {
            ++$stats['write_failures'];
            if ($written !== false && $written > 0) { ++$stats['partial_records']; }
            $stats['terminal_reason'] = 'short_or_failed_write'; break;
        }
        ++$stats['accepted'];
    }
} catch (\Throwable) {
    $stats['terminal_reason'] = 'collector_exception';
} finally {
    socket_close($socket); fclose($file);
    // Fixed counters only; never echo rejected datagrams or output filenames.
    fwrite(STDOUT, json_encode($stats, JSON_THROW_ON_ERROR).PHP_EOL);
}
