<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dish_id')->constrained()->onDelete('cascade');
            $table->string('code_url')->unique();
            $table->binary('qr_data')->nullable(); // Practical choice: QR codes rarely exceed 16MB
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            // Note: code_url unique index auto-created by ->unique()
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
