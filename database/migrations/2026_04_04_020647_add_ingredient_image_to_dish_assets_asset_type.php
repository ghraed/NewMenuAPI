<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dish_assets')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE dish_assets MODIFY asset_type ENUM('usdz', 'glb', 'preview_image', 'ingredient_image') NOT NULL"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('dish_assets')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('dish_assets')
            ->where('asset_type', 'ingredient_image')
            ->delete();

        DB::statement(
            "ALTER TABLE dish_assets MODIFY asset_type ENUM('usdz', 'glb', 'preview_image') NOT NULL"
        );
    }
};
