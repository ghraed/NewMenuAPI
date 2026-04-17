<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->enum('unit', ['g', 'ml', 'piece']);
            $table->timestamps();

            $table->unique(['dish_id', 'ingredient_id']);
            $table->index(['ingredient_id', 'dish_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_ingredients');
    }
};
