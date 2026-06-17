<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurants', 'custom_domain')) {
                $table->string('custom_domain')->nullable()->unique()->after('slug');
            }

            if (! Schema::hasColumn('restaurants', 'custom_domain_status')) {
                $table->string('custom_domain_status')->nullable()->after('custom_domain');
            }

            if (! Schema::hasColumn('restaurants', 'custom_domain_error')) {
                $table->text('custom_domain_error')->nullable()->after('custom_domain_status');
            }

            if (! Schema::hasColumn('restaurants', 'ssl_issued_at')) {
                $table->timestamp('ssl_issued_at')->nullable()->after('custom_domain_error');
            }
        });

        if (! Schema::hasTable('restaurant_domains') || ! Schema::hasColumn('restaurants', 'custom_domain')) {
            return;
        }

        $customDomains = DB::table('restaurant_domains')
            ->select(['restaurant_id', 'domain'])
            ->where('kind', 'custom')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->unique('restaurant_id');

        foreach ($customDomains as $customDomain) {
            DB::table('restaurants')
                ->where('id', $customDomain->restaurant_id)
                ->whereNull('custom_domain')
                ->update([
                    'custom_domain' => $customDomain->domain,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('restaurants', 'ssl_issued_at')) {
                $table->dropColumn('ssl_issued_at');
            }

            if (Schema::hasColumn('restaurants', 'custom_domain_error')) {
                $table->dropColumn('custom_domain_error');
            }

            if (Schema::hasColumn('restaurants', 'custom_domain_status')) {
                $table->dropColumn('custom_domain_status');
            }

            if (Schema::hasColumn('restaurants', 'custom_domain')) {
                $table->dropUnique('restaurants_custom_domain_unique');
                $table->dropColumn('custom_domain');
            }
        });
    }
};
