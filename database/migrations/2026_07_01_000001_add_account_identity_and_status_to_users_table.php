<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pbb_user_id', 26)->nullable()->unique()->after('id');
            $table->string('status', 20)->default('active')->after('user_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['pbb_user_id']);
            $table->dropColumn(['pbb_user_id', 'status']);
        });
    }
};
