<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('is_active')
                ->default(true)
                ->after('low_stock_threshold');

            $table->index(['restaurant_id', 'is_active'], 'ingredients_restaurant_is_active_index');
            $table->string('file_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('ingredients')
            ->whereNull('file_path')
            ->update(['file_path' => '']);

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex('ingredients_restaurant_is_active_index');
            $table->dropColumn('is_active');
            $table->string('file_path')->nullable(false)->change();
        });
    }
};
