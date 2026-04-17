<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('movement_type', [
                'opening_balance',
                'restock',
                'manual_adjustment',
                'order_consumption',
                'order_restoration',
            ]);
            $table->enum('unit', ['g', 'ml', 'piece']);
            $table->decimal('quantity_delta', 12, 3);
            $table->decimal('quantity_before', 12, 3)->nullable();
            $table->decimal('quantity_after', 12, 3)->nullable();
            $table->string('ingredient_name_snapshot');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['ingredient_id', 'occurred_at']);
            $table->index(['restaurant_id', 'occurred_at']);
            $table->index(['order_id', 'movement_type']);
            $table->index(['order_item_id', 'movement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
