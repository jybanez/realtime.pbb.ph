<?php

namespace Tests\Unit;

use App\Realtime\Observability\RealtimeDiagnosticRecord;
use PHPUnit\Framework\TestCase;

class RealtimeDiagnosticRecordTest extends TestCase
{
    public function test_collector_rejects_extra_private_fields_and_invalid_records(): void
    {
        $record = ['sequence' => 1, 'level' => 'info', 'message' => 'Realtime gateway callback timing.', 'context' => ['pid' => 123, 'request_id' => 'request-one', 'sql_operations' => ['SELECT' => 1]]];
        $this->assertTrue(RealtimeDiagnosticRecord::valid(json_encode($record)));
        $record['context']['token'] = 'PRIVATE';
        $this->assertFalse(RealtimeDiagnosticRecord::valid(json_encode($record)));
        unset($record['context']['token']);
        $record['context']['sql_operations']['PRIVATE'] = 1;
        $this->assertFalse(RealtimeDiagnosticRecord::valid(json_encode($record)));
        $this->assertFalse(RealtimeDiagnosticRecord::valid(str_repeat('x', 2049)));
        $this->assertFalse(RealtimeDiagnosticRecord::valid('{'));
    }

    public function test_signal_enum_and_numeric_field_semantics_are_enforced(): void
    {
        $record = ['sequence' => 1, 'level' => 'info', 'message' => 'Realtime gateway callback timing.', 'context' => ['pid' => 123, 'signal_type' => 'hangup', 'sql_scope' => 'inclusive']];
        $this->assertTrue(RealtimeDiagnosticRecord::valid(json_encode($record)));
        $record['context']['signal_type'] = 'arbitrary-private-value';
        $this->assertFalse(RealtimeDiagnosticRecord::valid(json_encode($record)));
        $record['context']['signal_type'] = 'other';
        $record['context']['elapsed_ms'] = 'private-value';
        $this->assertFalse(RealtimeDiagnosticRecord::valid(json_encode($record)));
    }
}
