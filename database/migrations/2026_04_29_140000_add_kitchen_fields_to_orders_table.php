<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('kitchen_status', 20)->nullable()->after('status');
            $table->timestamp('kitchen_started_at')->nullable()->after('accounted_at');
            $table->timestamp('kitchen_ready_at')->nullable()->after('kitchen_started_at');
            $table->timestamp('kitchen_completed_at')->nullable()->after('kitchen_ready_at');
            $table->foreignId('kitchen_updated_by')->nullable()->after('kitchen_completed_at')->constrained('users')->nullOnDelete();

            $table->index(['restaurant_id', 'kitchen_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_restaurant_id_kitchen_status_index');
            $table->dropConstrainedForeignId('kitchen_updated_by');
            $table->dropColumn('kitchen_completed_at');
            $table->dropColumn('kitchen_ready_at');
            $table->dropColumn('kitchen_started_at');
            $table->dropColumn('kitchen_status');
        });
    }
};
