<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_policies', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('policy_code')
                ->constrained('realtime_clients')
                ->nullOnDelete();
        });

        DB::table('realtime_policies')
            ->orderBy('id')
            ->get()
            ->each(function (object $policy): void {
                $clientId = DB::table('realtime_projects')
                    ->where('policy_profile_code', $policy->policy_code)
                    ->value('client_id');

                if (! $clientId && filled($policy->owner_team)) {
                    $clientId = DB::table('realtime_clients')
                        ->where('name', $policy->owner_team)
                        ->value('id');
                }

                if ($clientId) {
                    DB::table('realtime_policies')
                        ->where('id', $policy->id)
                        ->update(['client_id' => $clientId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('realtime_policies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
