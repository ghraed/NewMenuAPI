<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_order_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['event_reservation_id', 'order_id']);
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_order_links');
    }
};

