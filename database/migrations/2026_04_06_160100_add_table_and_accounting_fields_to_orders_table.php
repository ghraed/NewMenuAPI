<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('restaurant_table_id')->nullable()->after('restaurant_id')->constrained('restaurant_tables')->nullOnDelete();
            $table->string('table_reference', 40)->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('confirmed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
            $table->foreignId('accounted_by')->nullable()->after('cancelled_by')->constrained('users')->nullOnDelete();
            $table->timestamp('accounted_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restaurant_table_id');
            $table->dropColumn('table_reference');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
            $table->dropConstrainedForeignId('accounted_by');
            $table->dropColumn('accounted_at');
        });
    }
};
