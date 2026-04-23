<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->boolean('is_anchor')
                ->default(false)
                ->after('status');

            $table->index(['restaurant_id', 'is_anchor'], 'dishes_restaurant_is_anchor_index');
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->dropIndex('dishes_restaurant_is_anchor_index');
            $table->dropColumn('is_anchor');
        });
    }
};
