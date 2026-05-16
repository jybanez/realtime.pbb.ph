<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_code')->unique();
            $table->string('name');
            $table->string('status', 32)->default('draft');
            $table->text('description')->nullable();
            $table->string('policy_category')->nullable();
            $table->string('owner_team')->nullable();
            $table->text('capability_profile')->nullable();
            $table->text('room_policy_profile')->nullable();
            $table->text('rate_limit_profile')->nullable();
            $table->text('session_limit_profile')->nullable();
            $table->string('allow_deny_mode', 32)->default('allowlist');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_policies');
    }
};
