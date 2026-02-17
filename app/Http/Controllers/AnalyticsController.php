<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Jenssegers\Agent\Agent;

class AnalyticsController extends Controller
{
    /**
     * Track analytics events from the AR menu frontend
     * POST /api/analytics/track
     */
    public function track(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_type' => 'required|string|max:50',
                'dish_id' => 'nullable|exists:dishes,id',
                'properties' => 'nullable|array',
                'session_id' => 'nullable|string|max:255',
                'duration_ms' => 'nullable|integer',
            ]);

            $deviceType = $this->detectDeviceType($request);
            $sessionId = $validated['session_id'] ?? (string) \Illuminate\Support\Str::uuid();

            $event = AnalyticsEvent::create([
                'event_type' => $validated['event_type'],
                'dish_id' => $validated['dish_id'] ?? null,
                'user_id' => auth()->id(),
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'session_id' => $sessionId,
                'device_type' => $deviceType,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'properties' => json_encode($validated['properties'] ?? []),
                'duration_ms' => $validated['duration_ms'] ?? null,
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'event_id' => $event->id,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'messages' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Analytics tracking failed: ' . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    private function detectDeviceType(Request $request): string
    {
        $agent = new Agent();
        $userAgent = $request->userAgent() ?? '';

        if ($agent->is('iOS') || str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            return 'ios';
        }

        if ($agent->is('Android') || str_contains($userAgent, 'Android')) {
            return 'android';
        }

        return 'desktop';
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('restaurant');

        $restaurantId = $user->restaurant?->id;
        if (!$restaurantId) {
            abort(403, 'No restaurant is linked to this account');
        }

        $stats = [
            'total_views' => AnalyticsEvent::whereHas('dish', function ($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })->where('event_type', 'dish_view')->count(),

            'ar_launches' => AnalyticsEvent::whereHas('dish', function ($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })->whereIn('event_type', ['ar_launch_attempt', 'ar_launch_success'])->count(),

            'top_dishes' => AnalyticsEvent::select('dish_id', DB::raw('count(*) as total'))
                ->whereHas('dish', function ($q) use ($restaurantId) {
                    $q->where('restaurant_id', $restaurantId);
                })
                ->where('event_type', '3d_model_loaded')
                ->with('dish:id,name')
                ->groupBy('dish_id')
                ->orderByDesc('total')
                ->limit(5)
                ->get(),

            'device_breakdown' => AnalyticsEvent::whereHas('dish', function ($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
                ->select('device_type', DB::raw('count(*) as count'))
                ->whereDate('created_at', '>=', now()->subDays(30))
                ->groupBy('device_type')
                ->get(),
        ];

        return response()->json($stats);
    }
}
