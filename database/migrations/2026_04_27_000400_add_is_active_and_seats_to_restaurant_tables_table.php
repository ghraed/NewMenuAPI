<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name');
            $table->unsignedSmallInteger('seats')->nullable()->after('is_active');
            $table->index(['restaurant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropIndex('restaurant_tables_restaurant_id_is_active_index');
            $table->dropColumn(['is_active', 'seats']);
        });
    }
};
