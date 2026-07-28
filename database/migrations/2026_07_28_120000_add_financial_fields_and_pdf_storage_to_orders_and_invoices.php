<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'service_charge_rate')) {
                $table->decimal('service_charge_rate', 5, 2)->default(0)->after('vat_rate');
            }

            if (! Schema::hasColumn('orders', 'service_charge_amount')) {
                $table->decimal('service_charge_amount', 10, 2)->default(0)->after('vat_amount');
            }

            if (! Schema::hasColumn('orders', 'currency')) {
                $table->char('currency', 3)->default('USD')->after('total');
            }

            if (! Schema::hasColumn('orders', 'exchange_rate')) {
                $table->decimal('exchange_rate', 14, 4)->default(1)->after('currency');
            }

            if (! Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 20)->nullable()->after('exchange_rate');
            }

            if (! Schema::hasColumn('orders', 'payment_reference')) {
                $table->string('payment_reference', 120)->nullable()->after('payment_method');
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'discount_type')) {
                $table->string('discount_type', 20)->nullable()->after('subtotal');
            }

            if (! Schema::hasColumn('invoices', 'discount_value')) {
                $table->decimal('discount_value', 12, 2)->default(0)->after('discount_type');
            }

            if (! Schema::hasColumn('invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_value');
            }

            if (! Schema::hasColumn('invoices', 'taxable_subtotal')) {
                $table->decimal('taxable_subtotal', 12, 2)->default(0)->after('discount_amount');
            }

            if (! Schema::hasColumn('invoices', 'service_charge_rate')) {
                $table->decimal('service_charge_rate', 5, 2)->nullable()->after('taxable_subtotal');
            }

            if (! Schema::hasColumn('invoices', 'service_charge_amount')) {
                $table->decimal('service_charge_amount', 12, 2)->default(0)->after('service_charge_rate');
            }

            if (! Schema::hasColumn('invoices', 'vat_rate')) {
                $table->decimal('vat_rate', 5, 2)->nullable()->after('service_charge_amount');
            }

            if (! Schema::hasColumn('invoices', 'vat_amount')) {
                $table->decimal('vat_amount', 12, 2)->default(0)->after('vat_rate');
            }

            if (! Schema::hasColumn('invoices', 'currency')) {
                $table->char('currency', 3)->default('USD')->after('total');
            }

            if (! Schema::hasColumn('invoices', 'exchange_rate')) {
                $table->decimal('exchange_rate', 14, 4)->default(1)->after('currency');
            }

            if (! Schema::hasColumn('invoices', 'payment_method')) {
                $table->string('payment_method', 20)->nullable()->after('exchange_rate');
            }

            if (! Schema::hasColumn('invoices', 'payment_reference')) {
                $table->string('payment_reference', 120)->nullable()->after('payment_method');
            }

            if (! Schema::hasColumn('invoices', 'pdf_disk')) {
                $table->string('pdf_disk', 40)->nullable()->after('payment_reference');
            }

            if (! Schema::hasColumn('invoices', 'pdf_path')) {
                $table->string('pdf_path')->nullable()->after('pdf_disk');
            }

            if (! Schema::hasColumn('invoices', 'pdf_generated_at')) {
                $table->timestamp('pdf_generated_at')->nullable()->after('pdf_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $columns = [
                'service_charge_rate',
                'service_charge_amount',
                'currency',
                'exchange_rate',
                'payment_method',
                'payment_reference',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $columns = [
                'discount_type',
                'discount_value',
                'discount_amount',
                'taxable_subtotal',
                'service_charge_rate',
                'service_charge_amount',
                'vat_rate',
                'vat_amount',
                'currency',
                'exchange_rate',
                'payment_method',
                'payment_reference',
                'pdf_disk',
                'pdf_path',
                'pdf_generated_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
