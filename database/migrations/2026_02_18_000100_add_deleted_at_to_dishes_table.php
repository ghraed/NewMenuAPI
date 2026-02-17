<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['restaurant_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->dropIndex('dishes_restaurant_id_deleted_at_index');
            $table->dropSoftDeletes();
        });
    }
};
