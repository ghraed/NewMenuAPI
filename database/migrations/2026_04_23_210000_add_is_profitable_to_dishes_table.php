<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->boolean('is_profitable')
                ->default(false)
                ->after('is_anchor');

            $table->index(['restaurant_id', 'is_profitable'], 'dishes_restaurant_is_profitable_index');
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->dropIndex('dishes_restaurant_is_profitable_index');
            $table->dropColumn('is_profitable');
        });
    }
};
