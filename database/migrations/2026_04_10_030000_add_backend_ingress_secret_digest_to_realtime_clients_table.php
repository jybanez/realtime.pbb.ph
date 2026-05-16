<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('realtime_clients', function (Blueprint $table) {
            $table->string('backend_ingress_secret_digest', 64)
                ->nullable()
                ->after('backend_ingress_secret_hash');
        });
    }

    public function down(): void
    {
        Schema::table('realtime_clients', function (Blueprint $table) {
            $table->dropColumn('backend_ingress_secret_digest');
        });
    }
};
