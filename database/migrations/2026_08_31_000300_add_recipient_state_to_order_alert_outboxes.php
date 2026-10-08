<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_alert_outboxes', function (Blueprint $table): void {
            $table->json('web_push_retryable_recipients')->nullable()->after('web_push_delivered_at');
            $table->json('mobile_push_retryable_recipients')->nullable()->after('mobile_push_delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_alert_outboxes', function (Blueprint $table): void {
            $table->dropColumn(['web_push_retryable_recipients', 'mobile_push_retryable_recipients']);
        });
    }
};
