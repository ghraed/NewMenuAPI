<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('table_session_id')
                ->nullable()
                ->after('restaurant_table_id')
                ->constrained('table_sessions')
                ->nullOnDelete();
        });

        Schema::table('table_waves', function (Blueprint $table) {
            $table->foreignId('table_session_id')
                ->nullable()
                ->after('restaurant_table_id')
                ->constrained('table_sessions')
                ->nullOnDelete();
            $table->string('request_type', 30)
                ->default('call_waiter')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('table_waves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('table_session_id');
            $table->dropColumn('request_type');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('table_session_id');
        });
    }
};
