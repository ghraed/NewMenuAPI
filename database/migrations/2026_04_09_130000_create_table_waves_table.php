<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_waves', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->string('status', 40)->default('pending');
            $table->string('table_reference', 40);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index(['restaurant_table_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_waves');
    }
};
