<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_server_events', function (Blueprint $table) {
            $table->index(
                ['client_code', 'created_at'],
                'realtime_server_events_client_code_created_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('realtime_server_events', function (Blueprint $table) {
            $table->dropIndex('realtime_server_events_client_code_created_at_index');
        });
    }
};
