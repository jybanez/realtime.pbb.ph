<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id')->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_identity');
            $table->string('action_type');
            $table->string('target_type');
            $table->string('target_code');
            $table->string('client_code')->nullable();
            $table->string('project_code')->nullable();
            $table->longText('before_state')->nullable();
            $table->longText('after_state')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_audit_events');
    }
};
