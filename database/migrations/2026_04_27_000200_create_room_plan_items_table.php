<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->string('type', 40);
            $table->string('label', 120);
            $table->decimal('x', 10, 2);
            $table->decimal('y', 10, 2);
            $table->decimal('width', 10, 2);
            $table->decimal('height', 10, 2);
            $table->decimal('rotation', 8, 2)->default(0);
            $table->unsignedSmallInteger('seats')->nullable();
            $table->integer('z_index')->default(0);
            $table->string('container', 20)->default('wrapper');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['room_plan_id', 'z_index']);
            $table->index(['room_plan_id', 'type']);
            $table->index(['room_plan_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_plan_items');
    }
};
