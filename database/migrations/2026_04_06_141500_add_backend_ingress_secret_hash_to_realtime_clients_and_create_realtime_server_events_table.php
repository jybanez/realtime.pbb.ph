<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_clients', function (Blueprint $table) {
            $table->string('backend_ingress_secret_hash')->nullable()->after('trusted_signing_profile');
        });

        Schema::create('realtime_server_events', function (Blueprint $table) {
            $table->id();
            $table->string('publish_id')->unique();
            $table->string('client_code');
            $table->string('project_code');
            $table->string('room');
            $table->string('event_type');
            $table->string('event_id')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->unsignedInteger('fanout_count')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['client_code', 'project_code']);
            $table->index(['room', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_server_events');

        Schema::table('realtime_clients', function (Blueprint $table) {
            $table->dropColumn('backend_ingress_secret_hash');
        });
    }
};
