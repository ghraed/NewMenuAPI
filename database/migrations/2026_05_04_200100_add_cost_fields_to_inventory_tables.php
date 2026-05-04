<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->bigInteger('unit_cost_cents')->nullable()->after('target_quantity');
            $table->bigInteger('average_cost_cents')->nullable()->after('unit_cost_cents');
            $table->bigInteger('last_cost_cents')->nullable()->after('average_cost_cents');
            $table->char('cost_currency', 3)->nullable()->after('last_cost_cents');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->bigInteger('unit_cost_cents')->nullable()->after('quantity_after');
            $table->bigInteger('total_cost_cents')->nullable()->after('unit_cost_cents');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropColumn([
                'unit_cost_cents',
                'total_cost_cents',
            ]);
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn([
                'unit_cost_cents',
                'average_cost_cents',
                'last_cost_cents',
                'cost_currency',
            ]);
        });
    }
};

