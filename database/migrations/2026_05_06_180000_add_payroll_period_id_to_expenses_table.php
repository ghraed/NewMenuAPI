<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignId('payroll_period_id')
                ->nullable()
                ->after('vendor_id')
                ->constrained('payroll_periods')
                ->nullOnDelete();

            $table->unique(['restaurant_id', 'payroll_period_id'], 'expenses_restaurant_payroll_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropUnique('expenses_restaurant_payroll_period_unique');
            $table->dropConstrainedForeignId('payroll_period_id');
        });
    }
};
