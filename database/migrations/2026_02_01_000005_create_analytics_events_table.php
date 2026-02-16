<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dish_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('restaurant_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('event_type', [
                'page_view',
                '3d_viewer_opened',
                '3d_model_loaded',      // Add this
                '3d_model_error',       // Add this
                'ar_launch_attempt',
                'ar_launch_success',
                'dish_view',            // Add this (if using)
                'qr_scan',              // Optional: track QR scans
            ]);

            $table->string('device_type', 50)->nullable();
            $table->string('platform', 50)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable(); // IPv6 max length
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->index(['restaurant_id', 'event_type', 'created_at']);
            $table->index(['dish_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
