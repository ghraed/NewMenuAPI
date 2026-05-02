<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table): void {
            $table->string('invoice_split_mode', 20)
                ->default('by_each_order')
                ->after('close_reason');
            $table->unsignedSmallInteger('invoice_split_count')
                ->nullable()
                ->after('invoice_split_mode');
        });
    }

    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_split_mode',
                'invoice_split_count',
            ]);
        });
    }
};
