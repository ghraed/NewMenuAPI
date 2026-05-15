<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'status')) {
                $table->string('status', 30)
                    ->default('normal')
                    ->after('line_subtotal');
            }
            if (! Schema::hasColumn('order_items', 'compensation_type')) {
                $table->string('compensation_type', 40)
                    ->default('none')
                    ->after('status');
            }
            if (! Schema::hasColumn('order_items', 'compensation_reason')) {
                $table->string('compensation_reason', 80)
                    ->nullable()
                    ->after('compensation_type');
            }
            if (! Schema::hasColumn('order_items', 'complaint_category')) {
                $table->string('complaint_category', 60)
                    ->nullable()
                    ->after('compensation_reason');
            }
            if (! Schema::hasColumn('order_items', 'operational_loss_category')) {
                $table->string('operational_loss_category', 80)
                    ->nullable()
                    ->after('complaint_category');
            }
            if (! Schema::hasColumn('order_items', 'adjustment_action_type')) {
                $table->string('adjustment_action_type', 80)
                    ->nullable()
                    ->after('operational_loss_category');
            }
            if (! Schema::hasColumn('order_items', 'compensation_note')) {
                $table->text('compensation_note')
                    ->nullable()
                    ->after('adjustment_action_type');
            }
            if (! Schema::hasColumn('order_items', 'approved_by_staff_id')) {
                $table->foreignId('approved_by_staff_id')
                    ->nullable()
                    ->after('compensation_note')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('order_items', 'approved_by_staff_name')) {
                $table->string('approved_by_staff_name', 120)
                    ->nullable()
                    ->after('approved_by_staff_id');
            }
            if (! Schema::hasColumn('order_items', 'approved_by_staff_role')) {
                $table->string('approved_by_staff_role', 40)
                    ->nullable()
                    ->after('approved_by_staff_name');
            }
            if (! Schema::hasColumn('order_items', 'approved_at')) {
                $table->timestamp('approved_at')
                    ->nullable()
                    ->after('approved_by_staff_role');
            }
            if (! Schema::hasColumn('order_items', 'original_unit_price')) {
                $table->decimal('original_unit_price', 10, 2)
                    ->nullable()
                    ->after('approved_at');
            }
            if (! Schema::hasColumn('order_items', 'final_unit_price')) {
                $table->decimal('final_unit_price', 10, 2)
                    ->nullable()
                    ->after('original_unit_price');
            }
            if (! Schema::hasColumn('order_items', 'partial_discount_percentage')) {
                $table->decimal('partial_discount_percentage', 5, 2)
                    ->nullable()
                    ->after('final_unit_price');
            }
            if (! Schema::hasColumn('order_items', 'partial_discount_type')) {
                $table->string('partial_discount_type', 20)
                    ->nullable()
                    ->after('partial_discount_percentage');
            }
            if (! Schema::hasColumn('order_items', 'partial_discount_value')) {
                $table->decimal('partial_discount_value', 10, 2)
                    ->nullable()
                    ->after('partial_discount_type');
            }
            if (! Schema::hasColumn('order_items', 'is_complimentary')) {
                $table->boolean('is_complimentary')
                    ->default(false)
                    ->after('partial_discount_value');
            }
            if (! Schema::hasColumn('order_items', 'accounting_bucket')) {
                $table->string('accounting_bucket', 80)
                    ->nullable()
                    ->after('is_complimentary');
            }
            if (! Schema::hasColumn('order_items', 'customer_satisfaction_rating')) {
                $table->unsignedTinyInteger('customer_satisfaction_rating')
                    ->nullable()
                    ->after('accounting_bucket');
            }
            if (! Schema::hasColumn('order_items', 'evidence_photo_url')) {
                $table->text('evidence_photo_url')
                    ->nullable()
                    ->after('customer_satisfaction_rating');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_items', 'order_item_id')) {
                $table->unsignedBigInteger('order_item_id')
                    ->nullable()
                    ->after('order_index');
            }
            if (! Schema::hasColumn('invoice_items', 'status')) {
                $table->string('status', 30)
                    ->default('normal')
                    ->after('order_item_id');
            }
            if (! Schema::hasColumn('invoice_items', 'compensation_type')) {
                $table->string('compensation_type', 40)
                    ->default('none')
                    ->after('status');
            }
            if (! Schema::hasColumn('invoice_items', 'compensation_reason')) {
                $table->string('compensation_reason', 80)
                    ->nullable()
                    ->after('compensation_type');
            }
            if (! Schema::hasColumn('invoice_items', 'complaint_category')) {
                $table->string('complaint_category', 60)
                    ->nullable()
                    ->after('compensation_reason');
            }
            if (! Schema::hasColumn('invoice_items', 'operational_loss_category')) {
                $table->string('operational_loss_category', 80)
                    ->nullable()
                    ->after('complaint_category');
            }
            if (! Schema::hasColumn('invoice_items', 'adjustment_action_type')) {
                $table->string('adjustment_action_type', 80)
                    ->nullable()
                    ->after('operational_loss_category');
            }
            if (! Schema::hasColumn('invoice_items', 'compensation_note')) {
                $table->text('compensation_note')
                    ->nullable()
                    ->after('adjustment_action_type');
            }
            if (! Schema::hasColumn('invoice_items', 'approved_by_staff_name')) {
                $table->string('approved_by_staff_name', 120)
                    ->nullable()
                    ->after('compensation_note');
            }
            if (! Schema::hasColumn('invoice_items', 'approved_by_staff_role')) {
                $table->string('approved_by_staff_role', 40)
                    ->nullable()
                    ->after('approved_by_staff_name');
            }
            if (! Schema::hasColumn('invoice_items', 'approved_at')) {
                $table->timestamp('approved_at')
                    ->nullable()
                    ->after('approved_by_staff_role');
            }
            if (! Schema::hasColumn('invoice_items', 'original_unit_price')) {
                $table->decimal('original_unit_price', 10, 2)
                    ->nullable()
                    ->after('approved_at');
            }
            if (! Schema::hasColumn('invoice_items', 'final_unit_price')) {
                $table->decimal('final_unit_price', 10, 2)
                    ->nullable()
                    ->after('original_unit_price');
            }
            if (! Schema::hasColumn('invoice_items', 'original_line_total')) {
                $table->decimal('original_line_total', 12, 2)
                    ->nullable()
                    ->after('final_unit_price');
            }
            if (! Schema::hasColumn('invoice_items', 'partial_discount_percentage')) {
                $table->decimal('partial_discount_percentage', 5, 2)
                    ->nullable()
                    ->after('original_line_total');
            }
            if (! Schema::hasColumn('invoice_items', 'partial_discount_type')) {
                $table->string('partial_discount_type', 20)
                    ->nullable()
                    ->after('partial_discount_percentage');
            }
            if (! Schema::hasColumn('invoice_items', 'partial_discount_value')) {
                $table->decimal('partial_discount_value', 10, 2)
                    ->nullable()
                    ->after('partial_discount_type');
            }
            if (! Schema::hasColumn('invoice_items', 'is_complimentary')) {
                $table->boolean('is_complimentary')
                    ->default(false)
                    ->after('partial_discount_value');
            }
            if (! Schema::hasColumn('invoice_items', 'accounting_bucket')) {
                $table->string('accounting_bucket', 80)
                    ->nullable()
                    ->after('is_complimentary');
            }
            if (! Schema::hasColumn('invoice_items', 'customer_satisfaction_rating')) {
                $table->unsignedTinyInteger('customer_satisfaction_rating')
                    ->nullable()
                    ->after('accounting_bucket');
            }
            if (! Schema::hasColumn('invoice_items', 'evidence_photo_url')) {
                $table->text('evidence_photo_url')
                    ->nullable()
                    ->after('customer_satisfaction_rating');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            foreach ([
                'evidence_photo_url',
                'customer_satisfaction_rating',
                'accounting_bucket',
                'is_complimentary',
                'partial_discount_value',
                'partial_discount_type',
                'partial_discount_percentage',
                'original_line_total',
                'final_unit_price',
                'original_unit_price',
                'approved_at',
                'approved_by_staff_role',
                'approved_by_staff_name',
                'compensation_note',
                'adjustment_action_type',
                'operational_loss_category',
                'complaint_category',
                'compensation_reason',
                'compensation_type',
                'status',
                'order_item_id',
            ] as $column) {
                if (Schema::hasColumn('invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('order_items', 'approved_by_staff_id')) {
                $table->dropConstrainedForeignId('approved_by_staff_id');
            }

            foreach ([
                'evidence_photo_url',
                'customer_satisfaction_rating',
                'accounting_bucket',
                'is_complimentary',
                'partial_discount_value',
                'partial_discount_type',
                'partial_discount_percentage',
                'final_unit_price',
                'original_unit_price',
                'approved_at',
                'approved_by_staff_role',
                'approved_by_staff_name',
                'compensation_note',
                'adjustment_action_type',
                'operational_loss_category',
                'complaint_category',
                'compensation_reason',
                'compensation_type',
                'status',
            ] as $column) {
                if (Schema::hasColumn('order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
