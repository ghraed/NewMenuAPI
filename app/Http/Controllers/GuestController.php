<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\Dish;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GuestController extends Controller
{
    public function listDishes(string $restaurant_slug): JsonResponse
    {
        $restaurant = Restaurant::query()
            ->where('slug', $restaurant_slug)
            ->firstOrFail();

        $dishes = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', 'published')
            ->whereHas('assets', function ($query) {
                $query->where('asset_type', 'glb');
            })
            ->with('assets')
            ->orderBy('name')
            ->get();

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ],
            'dishes' => $dishes,
        ]);
    }

    public function showDish(string $restaurant_slug, int $dish_id, Request $request): JsonResponse
    {
        $dish = Dish::query()
            ->whereHas('restaurant', function ($query) use ($restaurant_slug) {
                $query->where('slug', $restaurant_slug);
            })
            ->where('id', $dish_id)
            ->where('status', 'published')
            ->whereHas('assets', function ($query) {
                $query->where('asset_type', 'glb');
            })
            ->with('assets')
            ->firstOrFail();

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

        $dish->load([
            'suggestedDishes' => function ($query) {
                $query->where('status', 'published')
                    ->whereHas('assets', function ($assetQuery) {
                        $assetQuery->where('asset_type', 'glb');
                    })
                    ->with('assets')
                    ->orderBy('name');
            },
            'relatedDishes' => function ($query) {
                $query->where('status', 'published')
                    ->whereHas('assets', function ($assetQuery) {
                        $assetQuery->where('asset_type', 'glb');
                    })
                    ->with('assets')
                    ->orderBy('name');
            },
        ]);

        return response()->json($dish);
    }

    public function listTables(string $restaurant_slug): JsonResponse
    {
        $restaurant = Restaurant::query()
            ->where('slug', $restaurant_slug)
            ->firstOrFail();

        $tables = $restaurant->tables()
            ->orderBy('name')
            ->get()
            ->map(fn ($table) => [
                'id' => $table->id,
                'name' => $table->name,
            ])
            ->values();

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ],
            'tables' => $tables,
        ]);
    }

    private function getDeviceType(Request $request): string
    {
        $ua = $request->userAgent();
        if (strpos($ua, 'iPad') !== false) {
            return 'tablet';
        }
        if (strpos($ua, 'Mobile') !== false) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function getPlatform(Request $request): string
    {
        $ua = $request->userAgent();
        if (strpos($ua, 'iPhone') !== false) {
            return 'ios';
        }
        if (strpos($ua, 'Android') !== false) {
            return 'android';
        }

        return 'unknown';
    }

    public function test(): int
    {
        return 2;
    }

    public function showTestDish(int $dishId)
    {
        $path = "dishes/{$dishId}/models/model.glb";
        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }
        $fullPath = Storage::disk('public')->path($path);

        return response()->download($fullPath, "dish_{$dishId}.glb", [
            'Content-Type' => 'model/gltf-binary',
            'ngrok-skip-browser-warning' => 'true',
        ]);
    }
}
