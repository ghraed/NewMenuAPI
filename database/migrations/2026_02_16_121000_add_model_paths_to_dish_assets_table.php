<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dish_assets', function (Blueprint $table) {
            $table->string('glb_path', 2048)->nullable()->after('file_url');
            $table->string('usdz_path', 2048)->nullable()->after('glb_path');
        });
    }

    public function down(): void
    {
        Schema::table('dish_assets', function (Blueprint $table) {
            $table->dropColumn(['glb_path', 'usdz_path']);
        });
    }
};
