<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_orders')) {
            return;
        }

        Schema::create('chat_orders', function (Blueprint $table): void {
            $table->id();
            $table->json('items');
            $table->string('status')->default('pending');
            $table->string('user_session_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_orders');
    }
};
