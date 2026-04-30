<?php

namespace App\Http\Controllers;

use App\Models\MobilePushToken;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function config(): JsonResponse
    {
        $publicKey = config('services.webpush.public_key');

        return response()->json([
            'supported' => filled($publicKey),
            'public_key' => $publicKey ?: null,
            'service_worker_url' => '/sw.js',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription' => 'required|array',
            'subscription.endpoint' => 'required|string|max:5000',
            'subscription.keys' => 'required|array',
            'subscription.keys.p256dh' => 'required|string|max:5000',
            'subscription.keys.auth' => 'required|string|max:5000',
            'subscription.contentEncoding' => 'nullable|string|max:50',
        ]);

        $subscription = $validated['subscription'];
        $user = $request->user();

        PushSubscription::query()->updateOrCreate(
            [
                'endpoint' => $subscription['endpoint'],
            ],
            [
                'user_id' => $user->id,
                'public_key' => $subscription['keys']['p256dh'],
                'auth_token' => $subscription['keys']['auth'],
                'content_encoding' => $subscription['contentEncoding'] ?? 'aesgcm',
                'user_agent' => $request->userAgent(),
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Push subscription saved successfully.',
        ]);
    }

    public function storeMobileToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:2048',
            'platform' => 'nullable|string|in:android,ios',
            'device_name' => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:64',
            'notify_wave' => 'nullable|boolean',
            'notify_order' => 'nullable|boolean',
        ]);

        $user = $request->user();

        MobilePushToken::query()->updateOrCreate(
            [
                'token' => $validated['token'],
            ],
            [
                'user_id' => $user->id,
                'platform' => $validated['platform'] ?? 'android',
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'notify_wave' => (bool) ($validated['notify_wave'] ?? true),
                'notify_order' => (bool) ($validated['notify_order'] ?? true),
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Mobile push token saved successfully.',
        ]);
    }
}
