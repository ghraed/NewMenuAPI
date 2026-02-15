<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\Dish;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GuestController extends Controller
{
    public function showDish($restaurant_slug, $dish_id, Request $request)
    {
        Log::info($restaurant_slug);
        Log::info($dish_id);
        $dish = Dish::whereHas('restaurant', function ($q) use ($restaurant_slug) {
            $q->where('slug', $restaurant_slug);
        })
            ->where('id', $dish_id)
            ->where('status', 'published')
            ->with('assets')
            ->firstOrFail();

        // Track page view
        AnalyticsEvent::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'dish_id' => $dish->id,
            'restaurant_id' => $dish->restaurant_id,
            'event_type' => 'page_view',
            'device_type' => $this->getDeviceType($request),
            'platform' => $this->getPlatform($request),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json($dish);
    }

    private function getDeviceType(Request $request): string
    {
        $ua = $request->userAgent();
        if (strpos($ua, 'iPad') !== false) return 'tablet';
        if (strpos($ua, 'Mobile') !== false) return 'mobile';
        return 'desktop';
    }

    private function getPlatform(Request $request): string
    {
        $ua = $request->userAgent();
        if (strpos($ua, 'iPhone') !== false) return 'ios';
        if (strpos($ua, 'Android') !== false) return 'android';
        return 'unknown';
    }

    function test()
    {
        return 123;
    }

    public function showTestDish($dishId)
    {
        $path = "dishes/{$dishId}/models/model.glb";
        if (!Storage::disk('public')->exists($path)) {
            abort(404);
        }
        $fullPath = Storage::disk('public')->path($path);

        // Force download with a meaningful .glb filename
        return response()->download($fullPath, "dish_{$dishId}.glb", [
            'Content-Type' => 'model/gltf-binary',
            'ngrok-skip-browser-warning' => 'true',  // 👈 Add directly here
        ]);
    }
}
