<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dish_ingredients', function (Blueprint $table) {
            $table->unsignedInteger('order_index')->default(0)->after('unit');
            $table->boolean('show_in_animation')->default(true)->after('order_index');
            $table->index(['dish_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::table('dish_ingredients', function (Blueprint $table) {
            $table->dropIndex(['dish_id', 'order_index']);
            $table->dropColumn(['order_index', 'show_in_animation']);
        });
    }
};

