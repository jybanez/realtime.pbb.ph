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
$stats = ['received' => 0, 'accepted' => 0, 'rejected' => 0, 'received_bytes' => 0, 'written_bytes' => 0, 'write_failures' => 0, 'kernel_or_sender_loss' => 'unknown'];
try {
    while (hrtime(true) < $deadline && $stats['received'] < 4096 && $stats['received_bytes'] < 4194304) {
        $data = ''; $peer = ''; $peerPort = 0;
        $length = @socket_recvfrom($socket, $data, 2049, 0, $peer, $peerPort);
        if ($length === false) { usleep(10000); continue; }
        ++$stats['received']; $stats['received_bytes'] += $length;
        if ($peer !== '127.0.0.1' || !\App\Realtime\Observability\RealtimeDiagnosticRecord::valid($data)) { ++$stats['rejected']; continue; }
        $line = $data.PHP_EOL;
        if ($stats['written_bytes'] + strlen($line) > 4194304) { ++$stats['rejected']; break; }
        if (fwrite($file, $line) !== strlen($line)) { ++$stats['write_failures']; break; }
        ++$stats['accepted']; $stats['written_bytes'] += strlen($line);
    }
} finally {
    socket_close($socket); fclose($file);
    // Fixed counters only; never echo rejected datagrams or output filenames.
    fwrite(STDOUT, json_encode($stats, JSON_THROW_ON_ERROR).PHP_EOL);
}
