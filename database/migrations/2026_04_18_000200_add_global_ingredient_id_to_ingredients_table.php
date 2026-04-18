<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->foreignId('global_ingredient_id')
                ->nullable()
                ->after('restaurant_id')
                ->constrained('global_ingredients')
                ->nullOnDelete();

            $table->index(['restaurant_id', 'global_ingredient_id'], 'ingredients_restaurant_global_ingredient_index');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex('ingredients_restaurant_global_ingredient_index');
            $table->dropConstrainedForeignId('global_ingredient_id');
        });
    }
};
