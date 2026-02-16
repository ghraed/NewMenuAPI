<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dish_id')->constrained()->onDelete('cascade');
            $table->enum('asset_type', ['usdz', 'glb', 'preview_image']);
            $table->string('file_path');
            $table->string('file_url');
            $table->bigInteger('file_size')->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent(); // Only created_at (no updated_at per SQL)
            $table->timestamp('updated_at')->useCurrent(); // Only created_at (no updated_at per SQL)
            $table->index(['dish_id', 'asset_type']); // Composite index preserved
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_assets');
    }
};