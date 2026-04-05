<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dish_id')->constrained('dishes')->onDelete('cascade');
            $table->foreignId('suggested_dish_id')->constrained('dishes')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['dish_id', 'suggested_dish_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_suggestions');
    }
};
