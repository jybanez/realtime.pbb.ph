<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_projects', function (Blueprint $table) {
            $table->text('media_ingest_settings')->nullable()->after('allowed_origins');
        });
    }

    public function down(): void
    {
        Schema::table('realtime_projects', function (Blueprint $table) {
            $table->dropColumn('media_ingest_settings');
        });
    }
};
