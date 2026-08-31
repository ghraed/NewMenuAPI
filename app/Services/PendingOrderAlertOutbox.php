<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PendingOrderAlertOutbox
{
    public function deliver(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $outbox = DB::table('order_alert_outboxes')
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();
            if (! $outbox) {
                return;
            }

            $errors = [];
            if (! $outbox->web_push_delivered_at) {
                try {
                    app(WebPushNotificationService::class)->notifyPendingOrderCreated($order);
                    DB::table('order_alert_outboxes')->where('id', $outbox->id)->update([
                        'web_push_delivered_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (Throwable $exception) {
                    $errors[] = 'web: '.$exception->getMessage();
                }
            }

            if (! $outbox->mobile_push_delivered_at) {
                try {
                    app(MobilePushNotificationService::class)->notifyPendingOrderCreated($order);
                    DB::table('order_alert_outboxes')->where('id', $outbox->id)->update([
                        'mobile_push_delivered_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (Throwable $exception) {
                    $errors[] = 'mobile: '.$exception->getMessage();
                }
            }

            DB::table('order_alert_outboxes')->where('id', $outbox->id)->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => $errors === [] ? null : implode('; ', $errors),
                'updated_at' => now(),
            ]);

            if ($errors !== []) {
                Log::warning('Pending order alert outbox delivery will be retried.', [
                    'order_id' => $order->id,
                    'channels' => count($errors),
                ]);
            }
        });
    }
}
