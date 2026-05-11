<?php

namespace App\Services;

use App\Events\EventPlanningNotification;
use App\Models\EventNotificationLog;
use App\Models\EventReservation;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventPlanningAlertService
{
    /** @var array<int, string> */
    public const TARGET_ROLES = [
        User::ROLE_ADMIN,
        User::ROLE_RESTAURANT_ADMIN,
        User::ROLE_CHEF,
        User::ROLE_STOCK_MANAGER,
    ];

    public function __construct(
        private readonly WebPushNotificationService $webPushNotificationService,
        private readonly MobilePushNotificationService $mobilePushNotificationService,
    ) {
    }

    public function dispatchImmediateUpdate(EventReservation $eventReservation, string $reason): void
    {
        $this->dispatch(
            eventReservation: $eventReservation,
            notificationType: EventReservation::NOTIFICATION_IMMEDIATE_UPDATE,
            title: 'Event planning updated',
            body: sprintf(
                '%s starts at %s.',
                $eventReservation->title,
                $eventReservation->start_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? 'N/A'
            ),
            reason: $reason,
            dedupeSuffix: 'immediate-'.Str::slug($reason),
            idempotent: false
        );
    }

    public function dispatchTMinusOneDayReminder(EventReservation $eventReservation): void
    {
        $this->dispatch(
            eventReservation: $eventReservation,
            notificationType: EventReservation::NOTIFICATION_T_MINUS_1D,
            title: 'Event planning reminder (T-1 day)',
            body: sprintf(
                '%s is scheduled to start within 24 hours.',
                $eventReservation->title
            ),
            reason: 't_minus_1d',
            dedupeSuffix: 't-minus-1d',
            idempotent: true
        );
    }

    private function dispatch(
        EventReservation $eventReservation,
        string $notificationType,
        string $title,
        string $body,
        string $reason,
        string $dedupeSuffix,
        bool $idempotent
    ): void {
        $eventReservation->loadMissing('restaurant');

        $payload = [
            'id' => $eventReservation->id,
            'restaurant_id' => $eventReservation->restaurant_id,
            'title' => $eventReservation->title,
            'status' => $eventReservation->status,
            'notification_type' => $notificationType,
            'reason' => $reason,
            'start_at' => $eventReservation->start_at?->toIso8601String(),
            'end_at' => $eventReservation->end_at?->toIso8601String(),
            'updated_at' => $eventReservation->updated_at?->toIso8601String(),
        ];

        try {
            event(new EventPlanningNotification($eventReservation, $payload));
            $this->storeLogRows(
                $eventReservation,
                $notificationType,
                'broadcast',
                $dedupeSuffix,
                $idempotent
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to broadcast event planning notification.', [
                'event_reservation_id' => $eventReservation->id,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            $this->webPushNotificationService->notifyEventPlanning(
                $eventReservation,
                $notificationType,
                $title,
                $body,
                self::TARGET_ROLES
            );
            $this->storeLogRows(
                $eventReservation,
                $notificationType,
                'web_push',
                $dedupeSuffix,
                $idempotent
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to send event planning web push notifications.', [
                'event_reservation_id' => $eventReservation->id,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            $this->mobilePushNotificationService->notifyEventPlanning(
                $eventReservation,
                $notificationType,
                $title,
                $body,
                self::TARGET_ROLES
            );
            $this->storeLogRows(
                $eventReservation,
                $notificationType,
                'mobile_push',
                $dedupeSuffix,
                $idempotent
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to send event planning mobile push notifications.', [
                'event_reservation_id' => $eventReservation->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function storeLogRows(
        EventReservation $eventReservation,
        string $notificationType,
        string $channel,
        string $dedupeSuffix,
        bool $idempotent
    ): void {
        $now = now();

        foreach (self::TARGET_ROLES as $role) {
            $dedupeKey = implode(':', [
                'event',
                (string) $eventReservation->id,
                $notificationType,
                $channel,
                $role,
                $idempotent
                    ? $dedupeSuffix
                    : $dedupeSuffix.'-'.sha1((string) $eventReservation->updated_at?->timestamp.'-'.$now->timestamp),
            ]);

            EventNotificationLog::query()->updateOrCreate(
                ['dedupe_key' => $dedupeKey],
                [
                    'event_reservation_id' => $eventReservation->id,
                    'notification_type' => $notificationType,
                    'channel' => $channel,
                    'sent_to_role' => $role,
                    'sent_at' => $now,
                ]
            );
        }
    }
}

