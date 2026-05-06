<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignId('linked_expense_id')
                ->nullable()
                ->after('order_item_id')
                ->constrained('expenses')
                ->nullOnDelete();

            $table->index(['restaurant_id', 'movement_type', 'linked_expense_id'], 'stock_moves_rest_type_expense_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_moves_rest_type_expense_idx');
            $table->dropConstrainedForeignId('linked_expense_id');
        });
    }
};
