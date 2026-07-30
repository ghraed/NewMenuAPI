<?php

namespace Tests\Feature;

use App\Models\EventReservation;
use App\Models\MobilePushToken;
use App\Models\PushSubscription;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\EventPlanningAlertService;
use App\Services\MobilePushNotificationService;
use App\Services\WebPushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Mockery;
use Tests\TestCase;

class NotificationDeliveryServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_push_event_notifications_target_only_same_restaurant_supported_roles_and_remove_expired_subscriptions(): void
    {
        config([
            'services.webpush.public_key' => 'public-key',
            'services.webpush.private_key' => 'private-key',
            'services.webpush.subject' => 'mailto:test@example.com',
        ]);

        $restaurant = $this->createRestaurant('web-push');
        $ownerSubscription = PushSubscription::query()->create([
            'user_id' => $restaurant->user_id,
            'endpoint' => 'https://push.example.com/owner',
            'public_key' => 'owner-public',
            'auth_token' => 'owner-auth',
            'content_encoding' => 'aesgcm',
        ]);

        $chef = User::factory()->chef()->attachedToRestaurant($restaurant)->create();
        $chefSubscription = PushSubscription::query()->create([
            'user_id' => $chef->id,
            'endpoint' => 'https://push.example.com/chef',
            'public_key' => 'chef-public',
            'auth_token' => 'chef-auth',
            'content_encoding' => 'aesgcm',
        ]);

        $accountant = User::factory()->accountant()->attachedToRestaurant($restaurant)->create();
        $accountantSubscription = PushSubscription::query()->create([
            'user_id' => $accountant->id,
            'endpoint' => 'https://push.example.com/accountant',
            'public_key' => 'accountant-public',
            'auth_token' => 'accountant-auth',
            'content_encoding' => 'aesgcm',
        ]);

        $otherRestaurant = $this->createRestaurant('web-push-other');
        $foreignChef = User::factory()->chef()->attachedToRestaurant($otherRestaurant)->create();
        $foreignSubscription = PushSubscription::query()->create([
            'user_id' => $foreignChef->id,
            'endpoint' => 'https://push.example.com/foreign',
            'public_key' => 'foreign-public',
            'auth_token' => 'foreign-auth',
            'content_encoding' => 'aesgcm',
        ]);

        $eventReservation = $this->createEvent($restaurant);

        $reportSuccess = Mockery::mock();
        $reportSuccess->shouldReceive('getEndpoint')->andReturn('https://push.example.com/owner');
        $reportSuccess->shouldReceive('isSuccess')->andReturn(true);

        $reportExpired = Mockery::mock();
        $reportExpired->shouldReceive('getEndpoint')->andReturn('https://push.example.com/chef');
        $reportExpired->shouldReceive('isSuccess')->andReturn(false);
        $reportExpired->shouldReceive('getReason')->andReturn('Expired subscription');
        $reportExpired->shouldReceive('isSubscriptionExpired')->andReturn(true);

        $webPush = Mockery::mock('overload:Minishlink\WebPush\WebPush');
        $webPush->shouldReceive('queueNotification')
            ->twice()
            ->with(
                Mockery::type(Subscription::class),
                Mockery::on(function (string $payload) use ($eventReservation): bool {
                    $decoded = json_decode($payload, true);

                    return is_array($decoded)
                        && ($decoded['data']['kind'] ?? null) === 'event_planning'
                        && ($decoded['data']['event_id'] ?? null) === $eventReservation->id
                        && ! isset($decoded['data']['customer_phone'])
                        && ! isset($decoded['data']['customer_email'])
                        && ! isset($decoded['data']['notes']);
                })
            );
        $webPush->shouldReceive('flush')->once()->andReturn([$reportSuccess, $reportExpired]);

        app(WebPushNotificationService::class)->notifyEventPlanning(
            $eventReservation,
            EventReservation::NOTIFICATION_T_MINUS_1D,
            'Reminder',
            'Event starts soon.',
            EventPlanningAlertService::TARGET_ROLES
        );

        $this->assertNotNull($ownerSubscription->fresh()?->last_used_at);
        $this->assertNull($chefSubscription->fresh());
        $this->assertNotNull($accountantSubscription->fresh());
        $this->assertNotNull($foreignSubscription->fresh());
    }

    public function test_mobile_push_event_notifications_target_only_same_restaurant_supported_roles_and_respect_preference_flags(): void
    {
        if (! function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('OpenSSL is required for mobile push notification tests.');
        }

        Cache::flush();
        $serviceAccountPath = $this->writeTempFcmServiceAccount();

        config([
            'services.fcm.service_account_json' => $serviceAccountPath,
            'services.fcm.project_id' => 'rozer-test-project',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fake-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'https://fcm.googleapis.com/v1/projects/rozer-test-project/messages:send' => Http::sequence()
                ->push(['name' => 'projects/rozer-test-project/messages/owner'], 200)
                ->push(['error' => 'invalid-registration-token'], 404),
        ]);

        $restaurant = $this->createRestaurant('mobile-push');
        $ownerToken = MobilePushToken::query()->create([
            'user_id' => $restaurant->user_id,
            'token' => 'owner-device-token',
            'platform' => 'android',
            'notify_order' => true,
            'notify_wave' => true,
        ]);

        $chef = User::factory()->chef()->attachedToRestaurant($restaurant)->create();
        $chefToken = MobilePushToken::query()->create([
            'user_id' => $chef->id,
            'token' => 'chef-device-token',
            'platform' => 'android',
            'notify_order' => true,
            'notify_wave' => true,
        ]);

        $accountant = User::factory()->accountant()->attachedToRestaurant($restaurant)->create();
        $accountantToken = MobilePushToken::query()->create([
            'user_id' => $accountant->id,
            'token' => 'accountant-device-token',
            'platform' => 'android',
            'notify_order' => true,
            'notify_wave' => true,
        ]);

        $stockManager = User::factory()->stockManager()->attachedToRestaurant($restaurant)->create();
        $mutedToken = MobilePushToken::query()->create([
            'user_id' => $stockManager->id,
            'token' => 'muted-device-token',
            'platform' => 'android',
            'notify_order' => false,
            'notify_wave' => true,
        ]);

        $otherRestaurant = $this->createRestaurant('mobile-push-other');
        $foreignChef = User::factory()->chef()->attachedToRestaurant($otherRestaurant)->create();
        $foreignToken = MobilePushToken::query()->create([
            'user_id' => $foreignChef->id,
            'token' => 'foreign-device-token',
            'platform' => 'android',
            'notify_order' => true,
            'notify_wave' => true,
        ]);

        $eventReservation = $this->createEvent($restaurant);

        app(MobilePushNotificationService::class)->notifyEventPlanning(
            $eventReservation,
            EventReservation::NOTIFICATION_T_MINUS_1D,
            'Reminder',
            'Event starts soon.',
            EventPlanningAlertService::TARGET_ROLES
        );

        Http::assertSentCount(3);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token';
        });
        Http::assertSent(function ($request) use ($eventReservation) {
            $payload = $request->data();

            return $request->url() === 'https://fcm.googleapis.com/v1/projects/rozer-test-project/messages:send'
                && ($payload['message']['token'] ?? null) === 'owner-device-token'
                && ($payload['message']['data']['kind'] ?? null) === 'event_planning'
                && ($payload['message']['data']['event_id'] ?? null) === (string) $eventReservation->id
                && ! isset($payload['message']['data']['customer_phone'])
                && ! isset($payload['message']['data']['customer_email'])
                && ! isset($payload['message']['data']['notes']);
        });
        Http::assertSent(function ($request) use ($eventReservation) {
            $payload = $request->data();

            return $request->url() === 'https://fcm.googleapis.com/v1/projects/rozer-test-project/messages:send'
                && ($payload['message']['token'] ?? null) === 'chef-device-token'
                && ($payload['message']['data']['kind'] ?? null) === 'event_planning'
                && ($payload['message']['data']['event_id'] ?? null) === (string) $eventReservation->id;
        });

        $this->assertNotNull($ownerToken->fresh()?->last_used_at);
        $this->assertNotNull($chefToken->fresh()?->last_used_at);
        $this->assertNull($accountantToken->fresh()?->last_used_at);
        $this->assertNull($mutedToken->fresh()?->last_used_at);
        $this->assertNull($foreignToken->fresh()?->last_used_at);
    }

    private function createRestaurant(string $slugPrefix, ?User $owner = null): Restaurant
    {
        $owner ??= User::factory()->admin()->create();

        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Notification Delivery '.Str::upper(Str::random(4)),
            'slug' => $slugPrefix.'-'.Str::lower(Str::random(6)),
            'description' => 'Notification delivery test restaurant',
            'address' => 'Beirut',
        ]);
    }

    private function createEvent(Restaurant $restaurant): EventReservation
    {
        return EventReservation::query()->create([
            'restaurant_id' => $restaurant->id,
            'title' => 'Event '.Str::upper(Str::random(4)),
            'customer_name' => 'Customer',
            'customer_phone' => '+96170000000',
            'customer_email' => 'customer@example.com',
            'start_at' => now()->addHours(8),
            'end_at' => now()->addHours(11),
            'status' => EventReservation::STATUS_CONFIRMED,
            'notes' => 'Internal notes that must stay private.',
        ]);
    }

    private function writeTempFcmServiceAccount(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->markTestSkipped('Failed to generate an OpenSSL private key for the FCM test.');
        }

        $privateKey = '';
        $exported = openssl_pkey_export($key, $privateKey);
        if (! $exported || $privateKey === '') {
            $this->markTestSkipped('Failed to export an OpenSSL private key for the FCM test.');
        }

        $path = sys_get_temp_dir().'/codex-fcm-'.Str::lower(Str::random(8)).'.json';
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'rozer-test-project',
            'private_key_id' => 'test-key-id',
            'private_key' => $privateKey,
            'client_email' => 'codex-fcm-test@example.iam.gserviceaccount.com',
            'client_id' => '1234567890',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
