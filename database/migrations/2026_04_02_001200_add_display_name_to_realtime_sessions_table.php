<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_sessions', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('app_code');
        });

        DB::table('realtime_sessions')
            ->whereNull('display_name')
            ->update([
                'display_name' => DB::raw('user_identity'),
            ]);
    }

    public function down(): void
    {
        Schema::table('realtime_sessions', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });
    }
};
