<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_order_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('table_session_id')->constrained()->cascadeOnDelete();
            $table->char('key_hash', 64);
            $table->char('payload_hash', 64);
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['table_session_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_order_idempotency');
    }
};
