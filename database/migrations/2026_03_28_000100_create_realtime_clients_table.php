<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_code')->unique();
            $table->string('name');
            $table->string('project_code');
            $table->string('status', 32)->default('pending');
            $table->text('description')->nullable();
            $table->string('integration_owner')->nullable();
            $table->text('integration_notes')->nullable();
            $table->string('issuer_identity')->nullable();
            $table->string('token_issuance_mode', 64)->default('app_backend_signed');
            $table->string('trusted_signing_profile')->nullable();
            $table->text('trust_notes')->nullable();
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
        Schema::dropIfExists('realtime_clients');
    }
};
