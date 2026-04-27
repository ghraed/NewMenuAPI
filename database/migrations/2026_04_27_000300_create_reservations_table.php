<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_plan_item_id')->constrained('room_plan_items')->cascadeOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40);
            $table->string('customer_email')->nullable();
            $table->date('reservation_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 30)->default('reserved');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'reservation_date']);
            $table->index(['room_plan_item_id', 'start_at', 'end_at']);
            $table->index(['room_plan_id', 'reservation_date']);
            $table->index(['status', 'reservation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
