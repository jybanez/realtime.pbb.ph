<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Realtime\Observability\RealtimeTransportObserver;
use GuzzleHttp\Psr7\Request;
use Ratchet\ConnectionInterface;

$receiver = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_bind($receiver, '127.0.0.1', 0);
socket_getsockname($receiver, $address, $port);
socket_set_option($receiver, SOL_SOCKET, SO_RCVBUF, 1024);
$samples = [];
try {
    foreach (range(1, 5) as $round) {
        foreach ([false, true] as $enabled) {
            $observer = $enabled ? new RealtimeTransportObserver($port) : null;
            $start = hrtime(true);
            for ($i = 0; $i < 1000; ++$i) {
                $conn = new class extends \stdClass implements ConnectionInterface {
                    public function send($data) { return $this; }
                    public function close() {}
                };
                $conn->httpRequest = new Request('GET', '/realtime?diag_attempt=12345678-1234-1234-1234-123456789abc');
                if ($observer) { $observer->accept($conn); $observer->firstData($conn); $observer->upgraded($conn); $observer->close($conn); }
            }
            $samples[$enabled ? 'enabled_stalled_udp_sink' : 'off'][] = (hrtime(true) - $start) / 1e6 / 1000;
        }
    }
    foreach ($samples as $mode => $values) {
        sort($values);
        echo json_encode(['mode' => $mode, 'median_ms_per_connection' => $values[2], 'min_ms' => min($values),
            'max_ms' => max($values), 'rounds' => 5, 'connections_per_round' => 1000]).PHP_EOL;
    }
} finally { socket_close($receiver); }
