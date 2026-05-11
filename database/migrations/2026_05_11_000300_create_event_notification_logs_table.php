<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_reservation_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type', 40);
            $table->string('channel', 40);
            $table->string('sent_to_role', 40);
            $table->string('dedupe_key', 190);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique('dedupe_key');
            $table->index(['event_reservation_id', 'notification_type'], 'enl_event_type_idx');
            $table->index(['sent_to_role', 'sent_at'], 'enl_role_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notification_logs');
    }
};
