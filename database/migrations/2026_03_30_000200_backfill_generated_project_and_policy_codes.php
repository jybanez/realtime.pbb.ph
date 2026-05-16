<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $clientProjectMap = [];
            $projectMap = [];
            $policyMap = [];

            $clients = DB::table('realtime_clients')
                ->select('id', 'project_code')
                ->orderBy('id')
                ->get();

            foreach ($clients as $client) {
                $oldCode = trim((string) $client->project_code);
                if ($oldCode === '' || str_starts_with($oldCode, 'prj_')) {
                    continue;
                }

                $newCode = $this->generateOpaqueCode('prj_', function (string $code): bool {
                    return DB::table('realtime_clients')->where('client_code', $code)->exists()
                        || DB::table('realtime_clients')->where('project_code', $code)->exists()
                        || DB::table('realtime_projects')->where('project_code', $code)->exists()
                        || DB::table('realtime_policies')->where('policy_code', $code)->exists();
                });

                DB::table('realtime_clients')
                    ->where('id', $client->id)
                    ->update(['project_code' => $newCode]);

                $clientProjectMap[$oldCode] = $newCode;
            }

            $projects = DB::table('realtime_projects')
                ->select('id', 'project_code', 'policy_profile_code')
                ->orderBy('id')
                ->get();

            foreach ($projects as $project) {
                $oldCode = trim((string) $project->project_code);
                if ($oldCode === '' || str_starts_with($oldCode, 'prj_')) {
                    continue;
                }

                $newCode = $this->generateOpaqueCode('prj_', function (string $code): bool {
                    return DB::table('realtime_clients')->where('client_code', $code)->exists()
                        || DB::table('realtime_clients')->where('project_code', $code)->exists()
                        || DB::table('realtime_projects')->where('project_code', $code)->exists()
                        || DB::table('realtime_policies')->where('policy_code', $code)->exists();
                });

                DB::table('realtime_projects')
                    ->where('id', $project->id)
                    ->update(['project_code' => $newCode]);

                $projectMap[$oldCode] = $newCode;
            }

            $policies = DB::table('realtime_policies')
                ->select('id', 'policy_code')
                ->orderBy('id')
                ->get();

            foreach ($policies as $policy) {
                $oldCode = trim((string) $policy->policy_code);
                if ($oldCode === '' || str_starts_with($oldCode, 'pol_')) {
                    continue;
                }

                $newCode = $this->generateOpaqueCode('pol_', function (string $code): bool {
                    return DB::table('realtime_clients')->where('client_code', $code)->exists()
                        || DB::table('realtime_clients')->where('project_code', $code)->exists()
                        || DB::table('realtime_projects')->where('project_code', $code)->exists()
                        || DB::table('realtime_policies')->where('policy_code', $code)->exists();
                });

                DB::table('realtime_policies')
                    ->where('id', $policy->id)
                    ->update(['policy_code' => $newCode]);

                $policyMap[$oldCode] = $newCode;
            }

            foreach ($projectMap as $oldCode => $newCode) {
                DB::table('realtime_sessions')
                    ->where('project_code', $oldCode)
                    ->update(['project_code' => $newCode]);

                DB::table('realtime_audit_events')
                    ->where('project_code', $oldCode)
                    ->update(['project_code' => $newCode]);
            }

            foreach ($policyMap as $oldCode => $newCode) {
                DB::table('realtime_projects')
                    ->where('policy_profile_code', $oldCode)
                    ->update(['policy_profile_code' => $newCode]);
            }

            $auditRows = DB::table('realtime_audit_events')
                ->select('audit_id', 'target_type', 'target_code', 'project_code', 'before_state', 'after_state')
                ->orderBy('id')
                ->get();

            foreach ($auditRows as $auditRow) {
                $updates = [];

                if (array_key_exists((string) $auditRow->target_code, $projectMap)) {
                    $updates['target_code'] = $projectMap[(string) $auditRow->target_code];
                } elseif (array_key_exists((string) $auditRow->target_code, $policyMap)) {
                    $updates['target_code'] = $policyMap[(string) $auditRow->target_code];
                } elseif (array_key_exists((string) $auditRow->target_code, $clientProjectMap)) {
                    $updates['target_code'] = $clientProjectMap[(string) $auditRow->target_code];
                }

                if (array_key_exists((string) $auditRow->project_code, $projectMap)) {
                    $updates['project_code'] = $projectMap[(string) $auditRow->project_code];
                } elseif (array_key_exists((string) $auditRow->project_code, $clientProjectMap)) {
                    $updates['project_code'] = $clientProjectMap[(string) $auditRow->project_code];
                }

                $beforeState = $this->replaceCodesInJson($auditRow->before_state, $projectMap, $policyMap, $clientProjectMap);
                $afterState = $this->replaceCodesInJson($auditRow->after_state, $projectMap, $policyMap, $clientProjectMap);

                $updates['before_state'] = $beforeState;
                $updates['after_state'] = $afterState;

                DB::table('realtime_audit_events')
                    ->where('audit_id', $auditRow->audit_id)
                    ->update($updates);
            }
        });
    }

    public function down(): void
    {
        // One-way data normalization migration.
    }

    protected function generateOpaqueCode(string $prefix, callable $exists): string
    {
        do {
            $code = $prefix . Str::ulid()->toBase32();
        } while ($exists($code));

        return $code;
    }

    protected function replaceCodesInJson(mixed $value, array $projectMap, array $policyMap, array $clientProjectMap): mixed
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return $value;
        }

        $replaced = $this->replaceRecursively($decoded, $projectMap, $policyMap, $clientProjectMap);

        return json_encode($replaced, JSON_UNESCAPED_SLASHES);
    }

    protected function replaceRecursively(mixed $value, array $projectMap, array $policyMap, array $clientProjectMap): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceRecursively($item, $projectMap, $policyMap, $clientProjectMap);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (array_key_exists($value, $projectMap)) {
            return $projectMap[$value];
        }

        if (array_key_exists($value, $policyMap)) {
            return $policyMap[$value];
        }

        if (array_key_exists($value, $clientProjectMap)) {
            return $clientProjectMap[$value];
        }

        return $value;
    }
};
