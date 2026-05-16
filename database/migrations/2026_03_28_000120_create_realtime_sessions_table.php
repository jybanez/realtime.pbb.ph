<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('client_code');
            $table->string('project_code');
            $table->string('app_code')->nullable();
            $table->string('user_identity');
            $table->string('status', 32)->default('connected');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->text('disconnect_reason')->nullable();
            $table->unsignedInteger('room_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_sessions');
    }
};
