<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\Dish;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            // Validate incoming data
            $validated = $request->validate([
                'event_type' => 'required|string|max:50',
                'dish_id' => 'nullable|exists:dishes,id',
                'properties' => 'nullable|array',
                'session_id' => 'nullable|string|max:255', // For guest tracking
                'duration_ms' => 'nullable|integer', // Time spent in 3D viewer
            ]);

            // Detect device type from User-Agent
            $deviceType = $this->detectDeviceType($request);

            // Get or create session ID for guests
            $sessionId = $validated['session_id'] ?? \Illuminate\Support\Str::uuid();

            // Create analytics record
            $event = AnalyticsEvent::create([
                'event_type' => $validated['event_type'],
                'dish_id' => $validated['dish_id'] ?? null,
                // 'user_id' => auth()->id(), // Null for guests, set for admins
                'user_id' => 1, // Null for guests, set for admins
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'session_id' => $sessionId,
                'device_type' => $deviceType,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'properties' => json_encode($validated['properties'] ?? []),
                'duration_ms' => $validated['duration_ms'] ?? null,
                'created_at' => now(),
            ]);

            // Optional: Real-time analytics processing via queue
            // dispatch(new ProcessAnalyticsEvent($event))->onQueue('analytics');

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
                'trace' => $e->getTraceAsString()
            ]);

            // Fail silently to frontend - don't break UX for analytics
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Detect device type for AR capability routing
     */
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

    /**
     * Get analytics dashboard data for admin
     * GET /api/analytics/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('view-analytics'); // Ensure admin role

        $restaurantId = Auth::user()->restaurant_id;

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
