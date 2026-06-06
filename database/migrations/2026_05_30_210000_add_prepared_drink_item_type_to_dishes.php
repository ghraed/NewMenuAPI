<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE dishes
            MODIFY item_type ENUM('prepared_dish', 'prepared_drink', 'packaged_drink', 'other_product')
            NOT NULL DEFAULT 'prepared_dish'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE dishes
            MODIFY item_type ENUM('prepared_dish', 'packaged_drink', 'other_product')
            NOT NULL DEFAULT 'prepared_dish'
        ");
    }
};

