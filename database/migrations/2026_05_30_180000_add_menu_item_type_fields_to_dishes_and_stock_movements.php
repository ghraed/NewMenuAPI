<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->enum('item_type', ['prepared_dish', 'packaged_drink', 'other_product'])
                ->default('prepared_dish')
                ->after('status');
            $table->foreignId('direct_stock_ingredient_id')
                ->nullable()
                ->after('item_type')
                ->constrained('ingredients')
                ->nullOnDelete();
            $table->decimal('direct_stock_quantity_per_sale', 12, 3)
                ->nullable()
                ->after('direct_stock_ingredient_id');
            $table->string('brand', 120)->nullable()->after('image_url');
            $table->string('barcode', 120)->nullable()->after('brand');
            $table->string('size_label', 120)->nullable()->after('barcode');
            $table->string('packaged_unit', 20)->nullable()->after('size_label');
            $table->decimal('cost_price', 12, 2)->nullable()->after('packaged_unit');
            $table->string('supplier', 180)->nullable()->after('cost_price');
            $table->decimal('packaged_stock_quantity', 12, 3)->nullable()->after('supplier');

            $table->index(['restaurant_id', 'item_type'], 'dishes_restaurant_item_type_index');
            $table->index(['restaurant_id', 'item_type', 'status'], 'dishes_restaurant_item_type_status_index');
            $table->index(['restaurant_id', 'category', 'status'], 'dishes_restaurant_category_status_index');
            $table->index(['restaurant_id', 'status', 'deleted_at'], 'dishes_restaurant_status_deleted_index');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('dish_id')
                ->nullable()
                ->after('order_item_id')
                ->constrained('dishes')
                ->nullOnDelete();
            $table->enum('inventory_source', ['recipe_ingredient_usage', 'direct_packaged_sale'])
                ->nullable()
                ->after('movement_type');

            $table->index(['dish_id', 'movement_type'], 'stock_movements_dish_type_index');
            $table->index(['restaurant_id', 'inventory_source'], 'stock_movements_restaurant_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_dish_type_index');
            $table->dropIndex('stock_movements_restaurant_source_index');
            $table->dropConstrainedForeignId('dish_id');
            $table->dropColumn('inventory_source');
        });

        Schema::table('dishes', function (Blueprint $table) {
            $table->dropIndex('dishes_restaurant_item_type_index');
            $table->dropIndex('dishes_restaurant_item_type_status_index');
            $table->dropIndex('dishes_restaurant_category_status_index');
            $table->dropIndex('dishes_restaurant_status_deleted_index');

            $table->dropConstrainedForeignId('direct_stock_ingredient_id');
            $table->dropColumn([
                'item_type',
                'direct_stock_quantity_per_sale',
                'brand',
                'barcode',
                'size_label',
                'packaged_unit',
                'cost_price',
                'supplier',
                'packaged_stock_quantity',
            ]);
        });
    }
};
