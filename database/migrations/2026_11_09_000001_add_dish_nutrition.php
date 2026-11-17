<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table): void {
            $table->decimal('calories', 10, 3)->nullable()->change();
            $table->decimal('protein', 10, 3)->nullable()->after('calories');
            $table->decimal('carbs', 10, 3)->nullable()->after('protein');
            $table->decimal('fat', 10, 3)->nullable()->after('carbs');
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table): void {
            $table->dropColumn(['protein', 'carbs', 'fat']);
            $table->unsignedInteger('calories')->nullable()->change();
        });
    }
};
