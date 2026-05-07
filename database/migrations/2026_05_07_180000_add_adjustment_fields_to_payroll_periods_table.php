<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->string('period_type', 20)->default('regular')->after('period_end');
            $table->foreignId('adjustment_of_period_id')
                ->nullable()
                ->after('period_type')
                ->constrained('payroll_periods')
                ->nullOnDelete();

            $table->index(['restaurant_id', 'period_type']);
            $table->index(['restaurant_id', 'adjustment_of_period_id'], 'payroll_period_adjustment_parent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropIndex(['restaurant_id', 'period_type']);
            $table->dropIndex('payroll_period_adjustment_parent_idx');
            $table->dropConstrainedForeignId('adjustment_of_period_id');
            $table->dropColumn('period_type');
        });
    }
};

