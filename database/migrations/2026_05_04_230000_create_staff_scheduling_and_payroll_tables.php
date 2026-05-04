<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('position', 80)->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'shift_date']);
            $table->index(['user_id', 'shift_date']);
        });

        Schema::create('payroll_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'period_start', 'period_end'], 'payroll_period_unique_window');
            $table->index(['restaurant_id', 'status']);
            $table->index(['restaurant_id', 'period_start', 'period_end']);
        });

        Schema::create('payroll_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('base_amount_cents')->default(0);
            $table->bigInteger('overtime_amount_cents')->default(0);
            $table->bigInteger('bonus_amount_cents')->default(0);
            $table->bigInteger('deduction_amount_cents')->default(0);
            $table->bigInteger('tax_amount_cents')->default(0);
            $table->bigInteger('net_amount_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'user_id']);
            $table->index(['restaurant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('staff_shifts');
    }
};
