<?php

namespace Tests\Unit;

use App\Realtime\Observability\RealtimeCallbackDiagnostics;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\TestCase;

class RealtimeCallbackDiagnosticsTest extends TestCase
{
    public function test_sql_is_scoped_to_active_nested_callbacks_without_payloads(): void
    {
        $diagnostics = new RealtimeCallbackDiagnostics();
        $query = new QueryExecuted('SELECT secret FROM private_table', ['sensitive-binding'], 12.5, $this->createMock(Connection::class));
        $diagnostics->query($query); // Outside callback: ignored.
        $diagnostics->begin('request', ['request_id' => 'request-one']);
        $diagnostics->begin('usage', []);
        $diagnostics->query($query);
        $child = $diagnostics->end(20);
        $parent = $diagnostics->end(30);
        $this->assertSame('request-one', $child['request_id']);
        $this->assertSame($parent['span_id'], $child['parent_span_id']);
        $this->assertSame(1, $child['sql_count']);
        $this->assertSame(12.5, $parent['sql_ms']);
        $this->assertSame(['SELECT' => 1], $parent['sql_operations']);
        $this->assertSame(20.0, $parent['measured_child_ms']);
        $this->assertSame(10.0, $parent['unaccounted_ms']);
        $this->assertStringNotContainsString('sensitive-binding', json_encode($parent));
        $this->assertStringNotContainsString('private_table', json_encode($parent));
        $diagnostics->begin('request', ['request_id' => 'request-two']);
        $next = $diagnostics->end(1);
        $this->assertSame(0, $next['sql_count']);
        $this->assertNull($next['parent_span_id']);
        $this->assertSame('request-two', $next['request_id']);
    }
}
