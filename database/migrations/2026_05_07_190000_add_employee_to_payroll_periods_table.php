<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('restaurant_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['restaurant_id', 'employee_id'], 'payroll_period_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropIndex('payroll_period_employee_idx');
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};

