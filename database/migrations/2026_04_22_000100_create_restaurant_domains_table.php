<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->enum('kind', ['subdomain', 'custom'])->default('subdomain');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_domains');
    }
};
