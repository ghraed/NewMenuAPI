<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('planned_quantity');
            $table->text('prep_notes')->nullable();
            $table->string('dish_name_snapshot', 255);
            $table->string('category_snapshot', 120)->nullable();
            $table->timestamps();

            $table->unique(['event_reservation_id', 'dish_id']);
            $table->index(['dish_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_menu_items');
    }
};

