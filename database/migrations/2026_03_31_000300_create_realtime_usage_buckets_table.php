<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('realtime_usage_buckets', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket_start');
            $table->string('bucket_granularity', 16);
            $table->string('client_code', 64)->default('');
            $table->string('project_code', 64)->default('');
            $table->string('event_type', 80);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->unsignedBigInteger('rate_limited_count')->default(0);
            $table->timestamps();

            $table->index(['bucket_start', 'bucket_granularity']);
            $table->index('client_code');
            $table->index('project_code');
            $table->index('event_type');
            $table->unique(
                ['bucket_start', 'bucket_granularity', 'client_code', 'project_code', 'event_type'],
                'realtime_usage_buckets_unique_bucket'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_usage_buckets');
    }
};
