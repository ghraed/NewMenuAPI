<?php

namespace App\Http\Controllers;

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
}
