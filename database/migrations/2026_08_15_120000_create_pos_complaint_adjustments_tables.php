<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_complaint_adjustments')) {
            Schema::create('pos_complaint_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('original_order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('original_invoice_number', 80)->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('complaint_reason', 80);
            $table->string('complaint_category', 60)->nullable();
            $table->text('complaint_note')->nullable();
            $table->string('accounting_bucket', 80)->default('customer_complaint_loss');
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('refund_payment_method', 20)->default('cash');
            $table->json('affected_items');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('gift_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
            $table->index(['restaurant_id', 'original_order_id']);
            $table->index(['restaurant_id', 'status']);
            });
        }

        if (! Schema::hasTable('pos_complaint_adjustment_gifts')) {
            Schema::create('pos_complaint_adjustment_gifts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('pos_complaint_adjustment_id');
            $table->foreign('pos_complaint_adjustment_id', 'pos_adj_gift_adjustment_fk')
                ->references('id')->on('pos_complaint_adjustments')->cascadeOnDelete();
            $table->foreignId('dish_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dish_name_snapshot');
            $table->decimal('quantity', 8, 3);
            $table->decimal('unit_value', 12, 2);
            $table->decimal('line_value', 12, 2);
            $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_complaint_adjustment_gifts');
        Schema::dropIfExists('pos_complaint_adjustments');
    }
};
