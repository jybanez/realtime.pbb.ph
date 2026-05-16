<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_server_events', function (Blueprint $table): void {
            $table->index(
                ['status', 'id'],
                'realtime_server_events_status_id_index'
            );
        });

        Schema::table('realtime_media_chunks', function (Blueprint $table): void {
            $table->index(
                ['status', 'id'],
                'realtime_media_chunks_status_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('realtime_media_chunks', function (Blueprint $table): void {
            $table->dropIndex('realtime_media_chunks_status_id_index');
        });

        Schema::table('realtime_server_events', function (Blueprint $table): void {
            $table->dropIndex('realtime_server_events_status_id_index');
        });
    }
};
