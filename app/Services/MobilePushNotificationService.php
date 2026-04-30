<?php

namespace App\Services;

use App\Models\MobilePushToken;
use App\Models\Order;
use App\Models\TableWave;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilePushNotificationService
{
    private const STAFF_ORDERS_URL = '/staff/orders';
    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_CACHE_TTL_SECONDS = 3000;

    public function isConfigured(): bool
    {
        $credentials = $this->loadServiceAccountCredentials();

        return filled($this->resolveProjectId($credentials))
            && filled($this->resolveServiceAccountPath())
            && is_file($this->resolveServiceAccountPath());
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

        $credentials = $this->loadServiceAccountCredentials();
        $projectId = $this->resolveProjectId($credentials);
        $accessToken = $this->getAccessToken($credentials);

        if ($projectId === null || $accessToken === null) {
            return;
        }

        foreach ($tokens as $pushToken) {
            if (! is_string($pushToken->token) || $pushToken->token === '') {
                continue;
            }

            $channelId = is_string(data_get($data, 'channel')) && data_get($data, 'channel') !== ''
                ? (string) data_get($data, 'channel')
                : 'staff_waves';
            $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type' => 'application/json',
                ])->post($endpoint, [
                    'message' => [
                        'token' => $pushToken->token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => $data + [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'android' => [
                            'priority' => 'HIGH',
                            'ttl' => '120s',
                            'notification' => [
                                'channel_id' => $channelId,
                                'sound' => 'default',
                                'default_sound' => true,
                                'visibility' => 'PUBLIC',
                            ],
                        ],
                    ],
                ]);

                if (! $response->ok()) {
                    Log::warning('FCM v1 push request failed.', [
                        'token_id' => $pushToken->id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('FCM v1 push request threw an exception.', [
                    'token_id' => $pushToken->id,
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

    private function resolveServiceAccountPath(): ?string
    {
        $path = (string) config('services.fcm.service_account_json');
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadServiceAccountCredentials(): ?array
    {
        $path = $this->resolveServiceAccountPath();
        if ($path === null || ! is_file($path)) {
            Log::warning('FCM service account file is missing.', ['path' => $path]);
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            Log::warning('FCM service account JSON is invalid.', ['path' => $path]);
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed>|null $credentials
     */
    private function resolveProjectId(?array $credentials = null): ?string
    {
        $configured = trim((string) config('services.fcm.project_id'));
        if ($configured !== '') {
            return $configured;
        }

        $projectId = $credentials ? Arr::get($credentials, 'project_id') : null;
        return is_string($projectId) && $projectId !== '' ? $projectId : null;
    }

    /**
     * @param array<string, mixed>|null $credentials
     */
    private function getAccessToken(?array $credentials): ?string
    {
        if (! is_array($credentials)) {
            return null;
        }

        $clientEmail = Arr::get($credentials, 'client_email');
        $privateKey = Arr::get($credentials, 'private_key');

        if (! is_string($clientEmail) || $clientEmail === '' || ! is_string($privateKey) || $privateKey === '') {
            Log::warning('FCM credentials are missing client_email/private_key.');
            return null;
        }

        $cacheKey = 'fcm:v1:oauth-token:'.sha1($clientEmail);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $issuedAt = time();
        $expiresAt = $issuedAt + 3600;

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => self::FCM_SCOPE,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ]));

        if ($header === null || $claims === null) {
            return null;
        }

        $unsignedJwt = $header.'.'.$claims;
        $signature = '';
        $signed = openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $signed) {
            Log::warning('Failed to sign FCM OAuth JWT.');
            return null;
        }

        $jwt = $unsignedJwt.'.'.$this->base64UrlEncode($signature);

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed requesting FCM OAuth token.', ['message' => $exception->getMessage()]);
            return null;
        }

        if (! $response->ok()) {
            Log::warning('FCM OAuth token request failed.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $token = data_get($response->json(), 'access_token');
        if (! is_string($token) || $token === '') {
            Log::warning('FCM OAuth token response missing access_token.');
            return null;
        }

        Cache::put($cacheKey, $token, now()->addSeconds(self::TOKEN_CACHE_TTL_SECONDS));

        return $token;
    }

    private function base64UrlEncode(string|false $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
