<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_ingredient_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dish_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dish_ingredient_id')->nullable()->constrained('dish_ingredients')->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ingredient_name_snapshot');
            $table->enum('unit', ['g', 'ml', 'piece']);
            $table->decimal('recipe_quantity_per_dish', 12, 3);
            $table->unsignedInteger('order_item_quantity');
            $table->decimal('consumed_quantity', 12, 3);
            $table->timestamps();

            $table->unique(['order_item_id', 'ingredient_id'], 'order_item_ingredient_usage_unique');
            $table->index(['restaurant_id', 'created_at']);
            $table->index(['order_id', 'order_item_id']);
            $table->index(['ingredient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_ingredient_usages');
    }
};
