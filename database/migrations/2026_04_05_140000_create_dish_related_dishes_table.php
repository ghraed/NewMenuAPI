<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_related_dishes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_dish_id')->constrained('dishes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['dish_id', 'related_dish_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_related_dishes');
    }
};
