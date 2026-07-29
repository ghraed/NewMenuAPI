<?php

namespace Tests\Feature;

use App\Console\Commands\SendEventPlanningReminders;
use App\Events\EventPlanningNotification;
use App\Models\EventNotificationLog;
use App\Models\EventReservation;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\EventPlanningAlertService;
use App\Services\MobilePushNotificationService;
use App\Services\WebPushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class EventPlanningAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_sends_only_due_confirmed_event_reminders_once(): void
    {
        $restaurant = $this->createRestaurant('event-reminder');
        $eligible = $this->createEvent($restaurant, EventReservation::STATUS_CONFIRMED, now()->addHours(12));
        $this->createEvent($restaurant, EventReservation::STATUS_DRAFT, now()->addHours(10));
        $this->createEvent($restaurant, EventReservation::STATUS_CONFIRMED, now()->addDays(2));
        $alreadyLogged = $this->createEvent($restaurant, EventReservation::STATUS_CONFIRMED, now()->addHours(8));

        EventNotificationLog::query()->create([
            'event_reservation_id' => $alreadyLogged->id,
            'notification_type' => EventReservation::NOTIFICATION_T_MINUS_1D,
            'channel' => 'broadcast',
            'sent_to_role' => User::ROLE_ADMIN,
            'dedupe_key' => 'existing-log',
            'sent_at' => now(),
        ]);

        $alertService = Mockery::mock(EventPlanningAlertService::class);
        $alertService->shouldReceive('dispatchTMinusOneDayReminder')
            ->once()
            ->withArgs(fn (EventReservation $event): bool => $event->is($eligible));
        app()->instance(EventPlanningAlertService::class, $alertService);
        app()->instance(SendEventPlanningReminders::class, new SendEventPlanningReminders($alertService));

        $this->artisan('events:send-planning-reminders')
            ->expectsOutput('Event planning reminders sent: 1')
            ->assertExitCode(0);
    }

    public function test_immediate_update_broadcast_payload_is_sanitized_and_logs_each_target_role_per_channel(): void
    {
        Event::fake([EventPlanningNotification::class]);

        $restaurant = $this->createRestaurant('event-alert-payload');
        $reservation = $this->createEvent(
            $restaurant,
            EventReservation::STATUS_CONFIRMED,
            now()->addHours(4),
            [
                'title' => 'Private Dinner',
                'customer_name' => 'Dana',
                'customer_phone' => '+96170000111',
                'customer_email' => 'dana@example.com',
                'notes' => 'VIP notes that must not broadcast',
            ]
        );

        $webPush = Mockery::mock(WebPushNotificationService::class);
        $webPush->shouldReceive('notifyEventPlanning')
            ->once()
            ->withArgs(function (
                EventReservation $event,
                string $notificationType,
                string $title,
                string $body,
                array $targetRoles
            ) use ($reservation): bool {
                return $event->is($reservation)
                    && $notificationType === EventReservation::NOTIFICATION_IMMEDIATE_UPDATE
                    && $title === 'Event planning updated'
                    && str_contains($body, 'Private Dinner')
                    && $targetRoles === EventPlanningAlertService::TARGET_ROLES;
            });

        $mobilePush = Mockery::mock(MobilePushNotificationService::class);
        $mobilePush->shouldReceive('notifyEventPlanning')
            ->once()
            ->withArgs(function (
                EventReservation $event,
                string $notificationType,
                string $title,
                string $body,
                array $targetRoles
            ) use ($reservation): bool {
                return $event->is($reservation)
                    && $notificationType === EventReservation::NOTIFICATION_IMMEDIATE_UPDATE
                    && $title === 'Event planning updated'
                    && str_contains($body, 'Private Dinner')
                    && $targetRoles === EventPlanningAlertService::TARGET_ROLES;
            });

        app()->instance(WebPushNotificationService::class, $webPush);
        app()->instance(MobilePushNotificationService::class, $mobilePush);

        app(EventPlanningAlertService::class)->dispatchImmediateUpdate($reservation, 'menu_changed');

        Event::assertDispatched(EventPlanningNotification::class, function (EventPlanningNotification $event) use ($reservation): bool {
            return $event->eventReservation->is($reservation)
                && ($event->payload['id'] ?? null) === $reservation->id
                && ($event->payload['restaurant_id'] ?? null) === $reservation->restaurant_id
                && ($event->payload['notification_type'] ?? null) === EventReservation::NOTIFICATION_IMMEDIATE_UPDATE
                && ($event->payload['reason'] ?? null) === 'menu_changed'
                && ! array_key_exists('customer_name', $event->payload)
                && ! array_key_exists('customer_phone', $event->payload)
                && ! array_key_exists('customer_email', $event->payload)
                && ! array_key_exists('notes', $event->payload);
        });

        $logs = EventNotificationLog::query()
            ->where('event_reservation_id', $reservation->id)
            ->orderBy('channel')
            ->orderBy('sent_to_role')
            ->get();

        $this->assertCount(12, $logs);
        $this->assertSame(
            ['broadcast', 'mobile_push', 'web_push'],
            $logs->pluck('channel')->unique()->values()->all()
        );
        $this->assertEqualsCanonicalizing(
            EventPlanningAlertService::TARGET_ROLES,
            $logs->pluck('sent_to_role')->unique()->values()->all()
        );
    }

    private function createRestaurant(string $slugPrefix, ?User $owner = null): Restaurant
    {
        $owner ??= User::factory()->admin()->create();

        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Event Alerts '.Str::upper(Str::random(4)),
            'slug' => $slugPrefix.'-'.Str::lower(Str::random(6)),
            'description' => 'Event alerts test restaurant',
            'address' => 'Beirut',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createEvent(Restaurant $restaurant, string $status, \DateTimeInterface $startAt, array $overrides = []): EventReservation
    {
        return EventReservation::query()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'title' => 'Event '.Str::upper(Str::random(4)),
            'customer_name' => 'Customer',
            'customer_phone' => '+96170000000',
            'customer_email' => 'customer@example.com',
            'start_at' => $startAt,
            'end_at' => (clone $startAt)->modify('+3 hours'),
            'status' => $status,
            'notes' => 'Internal notes',
        ], $overrides));
    }
}
