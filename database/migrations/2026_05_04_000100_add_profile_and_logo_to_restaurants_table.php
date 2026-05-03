<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            if (! Schema::hasColumn('restaurants', 'logo_path')) {
                $table->string('logo_path')->nullable()->after('dollar_rate');
            }

            if (! Schema::hasColumn('restaurants', 'profile')) {
                $table->json('profile')->nullable()->after('logo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $dropColumns = [];

            if (Schema::hasColumn('restaurants', 'profile')) {
                $dropColumns[] = 'profile';
            }

            if (Schema::hasColumn('restaurants', 'logo_path')) {
                $dropColumns[] = 'logo_path';
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
