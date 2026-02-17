<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DishController extends Controller
{
    public function index(Request $request)
    {
        $restaurant = $this->getRestaurantForRequest($request);

        return response()->json(
            $restaurant->dishes()
                ->with('assets')
                ->orderByDesc('id')
                ->paginate(15)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'category' => 'required|string|max:100',
            'status' => 'nullable|in:draft,published',
            'image_url' => 'nullable|url',
            'glb_file' => 'nullable|file|mimes:glb,gltf|max:51200|required_without:usdz_file',
            'usdz_file' => 'nullable|file|mimes:usdz|max:51200|required_without:glb_file',
        ]);

        $restaurant = $this->getRestaurantForRequest($request);

        $validated['uuid'] = (string) Str::uuid();
        $status = $validated['status'] ?? 'published';
        unset($validated['status']);
        $dish = $restaurant->dishes()->create(
            array_merge($validated, ['status' => $status])
        );

        if ($request->hasFile('glb_file')) {
            $file = $request->file('glb_file');
            $path = $file->store("dishes/{$dish->id}", 'public');

            DishAsset::create([
                'uuid' => (string) Str::uuid(),
                'dish_id' => $dish->id,
                'asset_type' => 'glb',
                'file_path' => $path,
                'glb_path' => $path,
                'file_url' => "/storage/{$path}",
                'file_size' => $file->getSize(),
                'mime_type' => 'model/gltf-binary',
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'file_name' => $file->getClientOriginalName(),
                ],
            ]);
        }

        if ($request->hasFile('usdz_file')) {
            $file = $request->file('usdz_file');
            $path = $file->store("dishes/{$dish->id}", 'public');

            DishAsset::create([
                'uuid' => (string) Str::uuid(),
                'dish_id' => $dish->id,
                'asset_type' => 'usdz',
                'file_path' => $path,
                'usdz_path' => $path,
                'file_url' => "/storage/{$path}",
                'file_size' => $file->getSize(),
                'mime_type' => 'model/vnd.usdz+zip',
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'file_name' => $file->getClientOriginalName(),
                ],
            ]);
        }

        return response()->json($dish->load('assets'), 201);
    }

    public function show(Request $request, Dish $dish)
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        return response()->json($dish->load('assets'));
    }

    public function update(Request $request, Dish $dish)
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'category' => 'sometimes|string|max:100',
            'status' => 'sometimes|in:draft,published',
            'image_url' => 'nullable|url',
        ]);

        $dish->update($validated);

        return response()->json($dish->load('assets'));
    }

    public function destroy(Request $request, Dish $dish)
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        $dish->loadMissing('assets');
        foreach ($dish->assets as $asset) {
            if ($asset->file_path) {
                Storage::disk('public')->delete($asset->file_path);
            }
        }

        $dish->delete();

        return response()->noContent();
    }

    public function publish(Request $request, Dish $dish)
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if (
            !$dish->assets()->where('asset_type', 'glb')->exists() &&
            !$dish->assets()->where('asset_type', 'usdz')->exists()
        ) {
            return response()->json([
                'message' => 'Cannot publish dish without 3D assets'
            ], 422);
        }

        $dish->update(['status' => 'published']);

        return response()->json($dish->load('assets'));
    }

    public function unpublish(Request $request, Dish $dish)
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        $dish->update(['status' => 'draft']);

        return response()->json($dish->load('assets'));
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant');

        if (!$user->restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $user->restaurant;
    }

    private function assertDishBelongsToRestaurant(Dish $dish, Restaurant $restaurant): void
    {
        if ($dish->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }
}
