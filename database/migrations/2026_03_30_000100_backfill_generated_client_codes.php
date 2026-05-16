<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $clients = DB::table('realtime_clients')
                ->select('id', 'client_code')
                ->orderBy('id')
                ->get();

            foreach ($clients as $client) {
                $oldCode = trim((string) $client->client_code);
                if ($oldCode === '' || str_starts_with($oldCode, 'clt_')) {
                    continue;
                }

                $newCode = $this->generateOpaqueClientCode();

                DB::table('realtime_clients')
                    ->where('id', $client->id)
                    ->update(['client_code' => $newCode]);

                DB::table('realtime_sessions')
                    ->where('client_code', $oldCode)
                    ->update(['client_code' => $newCode]);

                $auditRows = DB::table('realtime_audit_events')
                    ->where('client_code', $oldCode)
                    ->get(['audit_id', 'target_type', 'target_code', 'before_state', 'after_state']);

                foreach ($auditRows as $auditRow) {
                    $beforeState = $this->replaceClientCodeInJson($auditRow->before_state, $oldCode, $newCode);
                    $afterState = $this->replaceClientCodeInJson($auditRow->after_state, $oldCode, $newCode);

                    $targetCode = $auditRow->target_type === 'realtime_client' && trim((string) $auditRow->target_code) === $oldCode
                        ? $newCode
                        : $auditRow->target_code;

                    DB::table('realtime_audit_events')
                        ->where('audit_id', $auditRow->audit_id)
                        ->update([
                            'client_code' => $newCode,
                            'target_code' => $targetCode,
                            'before_state' => $beforeState,
                            'after_state' => $afterState,
                        ]);
                }
            }
        });
    }

    public function down(): void
    {
        // One-way data normalization migration.
    }

    protected function generateOpaqueClientCode(): string
    {
        do {
            $code = 'clt_' . Str::ulid()->toBase32();
        } while (DB::table('realtime_clients')->where('client_code', $code)->exists());

        return $code;
    }

    protected function replaceClientCodeInJson(mixed $value, string $oldCode, string $newCode): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return $value;
        }

        $replaced = $this->replaceRecursively($decoded, $oldCode, $newCode);

        return json_encode($replaced, JSON_UNESCAPED_SLASHES);
    }

    protected function replaceRecursively(mixed $value, string $oldCode, string $newCode): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceRecursively($item, $oldCode, $newCode);
            }

            return $value;
        }

        if (is_string($value) && $value === $oldCode) {
            return $newCode;
        }

        return $value;
    }
};
