<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('price');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('slug');
            $table->decimal('dollar_rate', 14, 2)->default(1)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['currency', 'dollar_rate']);
        });
    }
};
