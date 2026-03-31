<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dish_assets')) {
            return;
        }

        Schema::table('dish_assets', function (Blueprint $table) {
            if (! Schema::hasColumn('dish_assets', 'storage_disk')) {
                $table->string('storage_disk')->default('public')->after('asset_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dish_assets') || ! Schema::hasColumn('dish_assets', 'storage_disk')) {
            return;
        }

        Schema::table('dish_assets', function (Blueprint $table) {
            $table->dropColumn('storage_disk');
        });
    }
};
