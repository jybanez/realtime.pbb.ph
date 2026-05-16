<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('realtime_clients')->cascadeOnDelete();
            $table->string('project_code')->unique();
            $table->string('name');
            $table->string('status', 32)->default('pending');
            $table->text('description')->nullable();
            $table->text('scope_notes')->nullable();
            $table->text('allowed_origins')->nullable();
            $table->string('origin_policy_mode', 32)->default('allowlist');
            $table->string('policy_profile_code')->nullable();
            $table->string('capability_profile_code')->nullable();
            $table->string('room_policy_profile_code')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_projects');
    }
};
