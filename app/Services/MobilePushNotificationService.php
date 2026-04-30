<?php

namespace App\Services;

use App\Models\MobilePushToken;
use App\Models\Order;
use App\Models\TableWave;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilePushNotificationService
{
    private const FCM_SEND_URL = 'https://fcm.googleapis.com/fcm/send';
    private const STAFF_ORDERS_URL = '/staff/orders';
    private const MAX_TOKENS_PER_BATCH = 500;

    public function isConfigured(): bool
    {
        return filled(config('services.fcm.server_key'));
    }

    public function notifyWaveCreated(TableWave $wave, bool $isReminder = false): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $wave->loadMissing([
            'restaurant.user.mobilePushTokens',
            'restaurantTable.staffUsers.mobilePushTokens',
        ]);

        $recipients = collect([$wave->restaurant?->user])
            ->filter()
            ->merge($wave->restaurantTable?->staffUsers ?? [])
            ->filter(fn (User $user) => $user->mobilePushTokens->isNotEmpty())
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $tableName = $wave->table_reference;
        $isBillRequest = $wave->request_type === TableWave::REQUEST_TYPE_REQUEST_BILL;

        if ($isBillRequest) {
            $title = $isReminder ? "Bill requested again from {$tableName}" : "Bill request from {$tableName}";
            $body = $isReminder
                ? "A guest at {$tableName} is still waiting for the bill."
                : "A guest at {$tableName} is requesting the bill.";
        } else {
            $title = $isReminder ? "Guest waved again from {$tableName}" : "Guest wave from {$tableName}";
            $body = $isReminder
                ? "A guest at {$tableName} is still asking for staff assistance."
                : "A guest at {$tableName} is requesting staff assistance.";
        }

        $this->dispatchToRecipients($recipients, 'notify_wave', $title, $body, [
            'kind' => 'wave',
            'wave_id' => (string) $wave->id,
            'request_type' => $wave->request_type,
            'table_reference' => $wave->table_reference,
            'target_path' => self::STAFF_ORDERS_URL,
            'channel' => 'staff_waves',
        ]);
    }

    public function notifyPendingOrderCreated(Order $order): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $order->loadMissing([
            'restaurant.user.mobilePushTokens',
            'restaurantTable.staffUsers.mobilePushTokens',
        ]);

        $recipients = collect([$order->restaurant?->user])
            ->filter()
            ->merge($order->restaurantTable?->staffUsers ?? [])
            ->filter(fn (User $user) => $user->mobilePushTokens->isNotEmpty())
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $tableReference = $order->table_reference ?: ($order->restaurantTable?->name ?? 'Table');
        $orderNumber = $order->order_number ?: ('#'.$order->id);

        $this->dispatchToRecipients(
            $recipients,
            'notify_order',
            "New order from {$tableReference}",
            "Order {$orderNumber} needs staff confirmation.",
            [
                'kind' => 'order',
                'order_id' => (string) $order->id,
                'order_number' => (string) $orderNumber,
                'table_reference' => (string) $tableReference,
                'target_path' => self::STAFF_ORDERS_URL,
                'channel' => 'staff_orders',
            ]
        );
    }

    /**
     * @param Collection<int, User> $recipients
     * @param array<string, string> $data
     */
    private function dispatchToRecipients(
        Collection $recipients,
        string $preferenceField,
        string $title,
        string $body,
        array $data
    ): void
    {
        /** @var Collection<int, MobilePushToken> $tokens */
        $tokens = $recipients
            ->flatMap(fn (User $user) => $user->mobilePushTokens)
            ->filter(fn (MobilePushToken $token) => (bool) ($token->{$preferenceField} ?? true))
            ->unique('token')
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $serverKey = (string) config('services.fcm.server_key');

        foreach ($tokens->chunk(self::MAX_TOKENS_PER_BATCH) as $chunk) {
            $registrationIds = $chunk
                ->pluck('token')
                ->filter(fn (mixed $token) => is_string($token) && $token !== '')
                ->values()
                ->all();

            if ($registrationIds === []) {
                continue;
            }

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'key='.$serverKey,
                    'Content-Type' => 'application/json',
                ])->post(self::FCM_SEND_URL, [
                    'registration_ids' => $registrationIds,
                    'priority' => 'high',
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => $data + [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'android' => [
                        'priority' => 'high',
                    ],
                ]);

                if (! $response->ok()) {
                    Log::warning('FCM push request failed.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('FCM push request threw an exception.', [
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $tokens->each(function (MobilePushToken $token): void {
            $token->forceFill([
                'last_used_at' => now(),
            ])->save();
        });
    }
}
