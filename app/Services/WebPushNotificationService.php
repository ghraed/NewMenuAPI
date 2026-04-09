<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\TableWave;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushNotificationService
{
    public function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key'))
            && filled(config('services.webpush.private_key'))
            && filled(config('services.webpush.subject'));
    }

    public function notifyWaveCreated(TableWave $wave, bool $isReminder = false): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $wave->loadMissing([
            'restaurant.user.pushSubscriptions',
            'restaurantTable.staffUsers.pushSubscriptions',
        ]);

        $recipients = collect([$wave->restaurant?->user])
            ->filter()
            ->merge($wave->restaurantTable?->staffUsers ?? [])
            ->filter(fn (User $user) => $user->pushSubscriptions->isNotEmpty())
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $tableName = $wave->table_reference;
        $restaurantName = $wave->restaurant?->name ?? 'Restaurant';
        $title = $isReminder ? "Guest waved again from {$tableName}" : "Guest wave from {$tableName}";
        $body = $isReminder
            ? "A guest at {$tableName} is still asking for staff assistance."
            : "A guest at {$tableName} is requesting staff assistance.";

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/vite.svg',
            'badge' => '/vite.svg',
            'tag' => 'table-wave-'.$wave->id,
            'url' => '/staff/orders',
            'data' => [
                'wave_id' => $wave->id,
                'table_reference' => $wave->table_reference,
                'restaurant_name' => $restaurantName,
            ],
        ]);

        if (! is_string($payload)) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);

        /** @var Collection<int, PushSubscription> $subscriptions */
        $subscriptions = $recipients
            ->flatMap(fn (User $user) => $user->pushSubscriptions)
            ->unique('endpoint')
            ->values();

        foreach ($subscriptions as $storedSubscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $storedSubscription->endpoint,
                    'publicKey' => $storedSubscription->public_key,
                    'authToken' => $storedSubscription->auth_token,
                    'contentEncoding' => $storedSubscription->content_encoding ?: 'aesgcm',
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            $storedSubscription = $subscriptions->firstWhere('endpoint', $endpoint);

            if (! $storedSubscription) {
                continue;
            }

            if ($report->isSuccess()) {
                $storedSubscription->forceFill([
                    'last_used_at' => now(),
                ])->save();
                continue;
            }

            Log::warning('Web push delivery failed for a staff subscription.', [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
            ]);

            if ($report->isSubscriptionExpired()) {
                $storedSubscription->delete();
            }
        }
    }
}
