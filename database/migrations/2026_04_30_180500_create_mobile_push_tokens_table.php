<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_push_tokens')) {
            return;
        }

        Schema::create('mobile_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 1024)->charset('ascii')->collation('ascii_general_ci')->unique();
            $table->string('platform', 32)->default('android');
            $table->string('device_name', 255)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->boolean('notify_wave')->default(true);
            $table->boolean('notify_order')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform']);
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_tokens');
    }
};
