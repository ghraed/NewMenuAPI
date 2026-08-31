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
                    $result = app(WebPushNotificationService::class)->notifyPendingOrderCreated(
                        $order,
                        $this->decodeRecipients($outbox->web_push_retryable_recipients)
                    );
                    $retryable = $this->retryableRecipients($result);
                    DB::table('order_alert_outboxes')->where('id', $outbox->id)->update([
                        'web_push_delivered_at' => $retryable === [] ? now() : null,
                        'web_push_retryable_recipients' => json_encode($retryable),
                        'updated_at' => now(),
                    ]);
                    if ($retryable !== []) {
                        $errors[] = 'web: '.count($retryable).' recipient(s) retryable';
                    }
                } catch (Throwable $exception) {
                    $errors[] = 'web: '.$exception->getMessage();
                }
            }

            if (! $outbox->mobile_push_delivered_at) {
                try {
                    $result = app(MobilePushNotificationService::class)->notifyPendingOrderCreated(
                        $order,
                        $this->decodeRecipients($outbox->mobile_push_retryable_recipients)
                    );
                    $retryable = $this->retryableRecipients($result);
                    DB::table('order_alert_outboxes')->where('id', $outbox->id)->update([
                        'mobile_push_delivered_at' => $retryable === [] ? now() : null,
                        'mobile_push_retryable_recipients' => json_encode($retryable),
                        'updated_at' => now(),
                    ]);
                    if ($retryable !== []) {
                        $errors[] = 'mobile: '.count($retryable).' recipient(s) retryable';
                    }
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

    private function decodeRecipients(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }

    private function retryableRecipients(mixed $result): array
    {
        if (! is_array($result) || ! isset($result['retryable']) || ! is_array($result['retryable'])) {
            throw new \RuntimeException('Push service did not return a structured delivery outcome.');
        }

        return array_values(array_unique(array_filter($result['retryable'], 'is_string')));
    }
}
