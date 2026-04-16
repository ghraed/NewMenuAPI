<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->unsignedInteger('table_number');
            $table->string('status', 20)->default('active');
            $table->text('pin_hash')->nullable();
            $table->unsignedSmallInteger('pin_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 40)->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['restaurant_id', 'table_number']);
            $table->index(['status', 'expires_at']);
            $table->index(['restaurant_table_id', 'status']);
        });

        Schema::create('table_guest_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_session_id')->constrained('table_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('device_fingerprint', 64)->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 40)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['table_session_id', 'expires_at']);
            $table->index(['table_session_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_guest_accesses');
        Schema::dropIfExists('table_sessions');
    }
};
