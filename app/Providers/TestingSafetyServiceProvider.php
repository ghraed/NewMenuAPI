<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class TestingSafetyServiceProvider extends ServiceProvider
{
    /**
     * @var array<int, string>
     */
    private const STORAGE_SERVICE_ENV_KEYS = [
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_BUCKET',
        'AWS_ENDPOINT',
        'B2_KEY_ID',
        'B2_APPLICATION_KEY',
        'B2_BUCKET',
        'B2_ENDPOINT',
    ];

    /**
     * @var array<int, string>
     */
    private const NOTIFICATION_SERVICE_ENV_KEYS = [
        'PUSHER_APP_ID',
        'PUSHER_APP_KEY',
        'PUSHER_APP_SECRET',
        'REVERB_APP_ID',
        'REVERB_APP_KEY',
        'REVERB_APP_SECRET',
        'WEB_PUSH_VAPID_PUBLIC_KEY',
        'WEB_PUSH_VAPID_PRIVATE_KEY',
        'WEB_PUSH_VAPID_SUBJECT',
        'FCM_SERVER_KEY',
        'FCM_PROJECT_ID',
        'FCM_SERVICE_ACCOUNT_JSON',
        'SLACK_BOT_USER_OAUTH_TOKEN',
        'SLACK_BOT_USER_DEFAULT_CHANNEL',
    ];

    /**
     * @var array<int, string>
     */
    private const PAYMENT_SERVICE_ENV_KEYS = [
        'STRIPE_KEY',
        'STRIPE_SECRET',
        'STRIPE_WEBHOOK_SECRET',
        'PAYPAL_CLIENT_ID',
        'PAYPAL_CLIENT_SECRET',
        'BRAINTREE_PUBLIC_KEY',
        'BRAINTREE_PRIVATE_KEY',
        'BRAINTREE_MERCHANT_ID',
        'SQUARE_ACCESS_TOKEN',
        'SQUARE_APPLICATION_ID',
    ];

    /**
     * @var array<int, string>
     */
    private const EXTERNAL_API_ENV_KEYS = [
        'DEEPSEEK_API_KEY',
        'OPENAI_API_KEY',
        'ANTHROPIC_API_KEY',
    ];

    public function register(): void
    {
        if (! $this->shouldApply()) {
            return;
        }

        $this->forceSafeTestConfig();
    }

    public function boot(): void
    {
        if (! $this->shouldApply()) {
            return;
        }

        $this->assertSafeEnvironment();
        $this->prepareTestFilesystemRoots();
    }

    private function shouldApply(): bool
    {
        return $this->app->environment('testing') || $this->app->runningUnitTests();
    }

    private function forceSafeTestConfig(): void
    {
        $this->app['config']->set([
            'app.debug' => false,
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'broadcasting.default' => 'null',
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => storage_path('framework/testing/disks/local'),
            'filesystems.disks.public.root' => storage_path('framework/testing/disks/public'),
            'filesystems.disks.public.url' => rtrim((string) env('APP_URL', 'http://testing.local'), '/').'/storage',
            'services.postmark.key' => null,
            'services.resend.key' => null,
            'services.ses.key' => null,
            'services.ses.secret' => null,
            'services.webpush.public_key' => 'testing-webpush-public-key',
            'services.webpush.private_key' => 'testing-webpush-private-key',
            'services.webpush.subject' => 'mailto:testing@example.invalid',
            'services.fcm.server_key' => 'testing-fcm-key',
            'services.fcm.project_id' => 'testing-project',
            'services.fcm.service_account_json' => storage_path('framework/testing/firebase/testing-service-account.json'),
            'services.deepseek.key' => 'testing-deepseek-key',
            'services.deepseek.base_url' => 'http://127.0.0.1/disabled-deepseek',
            'services.deepseek.api_url' => 'http://127.0.0.1/disabled-deepseek/chat/completions',
        ]);
    }

    private function assertSafeEnvironment(): void
    {
        if ($this->app->environment('production')) {
            throw new RuntimeException('Refusing to run tests with APP_ENV=production.');
        }

        if (! $this->app->environment('testing')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests outside the testing environment. Current APP_ENV=%s.',
                $this->app->environment()
            ));
        }

        $this->assertTestDatabase();
        $this->assertSafeMailConfig();
        $this->assertSafeRedisConfig();
        $this->assertSafeStorageConfig();
        $this->assertSafeQueueConfig();
        $this->assertSafeNotificationConfig();
        $this->assertSafeExternalApiConfig();
    }

    private function assertTestDatabase(): void
    {
        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw new RuntimeException("Database connection [{$connectionName}] is not configured for tests.");
        }

        $database = (string) ($connection['database'] ?? '');
        $driver = (string) ($connection['driver'] ?? '');

        if ($driver === 'sqlite') {
            if ($database !== ':memory:' && ! preg_match('/(?:^|[\/._-])(test|testing)(?:[\/._-]|$)/i', $database)) {
                throw new RuntimeException("SQLite test database path [{$database}] is not clearly a test database.");
            }

            return;
        }

        if (! preg_match('/(^|_|-)(test|testing|ci)($|_|-)/i', $database)) {
            throw new RuntimeException("Database [{$database}] is not clearly a dedicated test database.");
        }
    }

    private function assertSafeMailConfig(): void
    {
        $mailer = (string) config('mail.default');

        if (! in_array($mailer, ['array', 'log'], true)) {
            throw new RuntimeException("Unsafe mailer [{$mailer}] configured for tests.");
        }

        $mailHost = trim((string) env('MAIL_HOST', ''));
        if ($mailHost !== '' && ! $this->isLocalHost($mailHost)) {
            throw new RuntimeException("Unsafe mail host [{$mailHost}] configured for tests.");
        }
    }

    private function assertSafeRedisConfig(): void
    {
        $redisUrl = trim((string) env('REDIS_URL', ''));
        $redisHost = trim((string) env('REDIS_HOST', '127.0.0.1'));

        if ($redisUrl !== '' && ! $this->isLocalUrl($redisUrl)) {
            throw new RuntimeException('Unsafe REDIS_URL configured for tests.');
        }

        if ($redisHost !== '' && ! $this->isLocalHost($redisHost)) {
            throw new RuntimeException("Unsafe Redis host [{$redisHost}] configured for tests.");
        }
    }

    private function assertSafeStorageConfig(): void
    {
        $disk = (string) config('filesystems.default');
        if (! in_array($disk, ['local', 'public'], true)) {
            throw new RuntimeException("Unsafe filesystem disk [{$disk}] configured for tests.");
        }

        foreach (self::STORAGE_SERVICE_ENV_KEYS as $key) {
            $this->assertEnvVarIsDisabled($key, 'storage');
        }
    }

    private function assertSafeQueueConfig(): void
    {
        $queue = (string) config('queue.default');
        if (! in_array($queue, ['sync', 'null'], true)) {
            throw new RuntimeException("Unsafe queue connection [{$queue}] configured for tests.");
        }

        $broadcast = (string) config('broadcasting.default');
        if (! in_array($broadcast, ['null', 'log'], true)) {
            throw new RuntimeException("Unsafe broadcast connection [{$broadcast}] configured for tests.");
        }
    }

    private function assertSafeNotificationConfig(): void
    {
        foreach (self::NOTIFICATION_SERVICE_ENV_KEYS as $key) {
            $this->assertEnvVarIsDisabled($key, 'notification');
        }
    }

    private function assertSafeExternalApiConfig(): void
    {
        foreach (self::PAYMENT_SERVICE_ENV_KEYS as $key) {
            $this->assertEnvVarIsDisabled($key, 'payment');
        }

        foreach (self::EXTERNAL_API_ENV_KEYS as $key) {
            $this->assertEnvVarIsDisabled($key, 'external API');
        }
    }

    private function assertEnvVarIsDisabled(string $key, string $serviceType): void
    {
        $value = trim((string) env($key, ''));

        if ($value === '') {
            return;
        }

        if ($this->isObviousTestPlaceholder($value)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Unsafe %s credential/config [%s] is present while running tests.',
            $serviceType,
            $key
        ));
    }

    private function prepareTestFilesystemRoots(): void
    {
        $paths = [
            storage_path('framework/testing/disks/local'),
            storage_path('framework/testing/disks/public'),
            storage_path('framework/testing/firebase'),
        ];

        foreach ($paths as $path) {
            File::ensureDirectoryExists($path);
        }
    }

    private function isLocalUrl(string $value): bool
    {
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return $this->isLocalHost($host);
    }

    private function isLocalHost(string $host): bool
    {
        $normalized = strtolower(trim($host));

        return in_array($normalized, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($normalized, '.localhost')
            || str_ends_with($normalized, '.test');
    }

    private function isObviousTestPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return true;
        }

        foreach (['test', 'testing', 'dummy', 'fake', 'example', 'disabled', 'local', 'invalid'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }
}
