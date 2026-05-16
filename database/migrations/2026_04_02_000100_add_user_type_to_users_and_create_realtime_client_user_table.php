<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('user_type', 20)->default('regular')->after('is_operator');
        });

        DB::table('users')
            ->where('is_operator', true)
            ->update(['user_type' => 'admin']);

        Schema::create('realtime_client_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('realtime_clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('assignment_role', 50)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_client_user');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('user_type');
        });
    }
};
