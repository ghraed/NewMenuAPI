<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->enum('stock_unit', ['g', 'ml', 'piece'])
                ->default('piece')
                ->after('name_ar');
            $table->decimal('current_stock_quantity', 12, 3)
                ->default(0)
                ->after('stock_unit');
            $table->decimal('low_stock_threshold', 12, 3)
                ->default(0)
                ->after('current_stock_quantity');

            $table->index(['restaurant_id', 'stock_unit'], 'ingredients_restaurant_stock_unit_index');
            $table->index(['restaurant_id', 'current_stock_quantity'], 'ingredients_restaurant_stock_quantity_index');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex('ingredients_restaurant_stock_unit_index');
            $table->dropIndex('ingredients_restaurant_stock_quantity_index');
            $table->dropColumn([
                'stock_unit',
                'current_stock_quantity',
                'low_stock_threshold',
            ]);
        });
    }
};
