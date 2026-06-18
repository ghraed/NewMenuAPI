<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            if (! Schema::hasColumn('restaurants', 'deleted_at')) {
                $table->softDeletes();
                $table->index(['status', 'deleted_at'], 'restaurants_status_deleted_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            if (Schema::hasColumn('restaurants', 'deleted_at')) {
                $table->dropIndex('restaurants_status_deleted_at_index');
                $table->dropSoftDeletes();
            }
        });
    }
};
