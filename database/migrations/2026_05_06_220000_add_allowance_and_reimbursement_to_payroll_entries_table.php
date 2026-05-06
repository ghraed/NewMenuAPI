<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->bigInteger('allowance_amount_cents')->default(0)->after('bonus_amount_cents');
            $table->bigInteger('reimbursement_amount_cents')->default(0)->after('allowance_amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->dropColumn(['allowance_amount_cents', 'reimbursement_amount_cents']);
        });
    }
};

