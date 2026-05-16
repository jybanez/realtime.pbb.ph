<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_media_chunks', function (Blueprint $table): void {
            $table->id();
            $table->string('chunk_id')->unique();
            $table->string('client_code')->index();
            $table->string('project_code')->index();
            $table->string('room');
            $table->string('session_id')->nullable()->index();
            $table->string('user_id')->nullable();
            $table->string('display_name')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('forwarded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->unsignedSmallInteger('downstream_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_media_chunks');
    }
};
