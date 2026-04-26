<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('restaurants', 'status')) {
            Schema::table('restaurants', function (Blueprint $table): void {
                $table->string('status', 30)->default('active')->after('slug');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurants', 'status')) {
            Schema::table('restaurants', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }
};
