<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\DishAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    public function upload(Request $request, Dish $dish)
    {
        $this->assertDishBelongsToCurrentUser($request, $dish);

        if ($dish->trashed()) {
            return response()->json([
                'message' => 'Cannot upload assets to a deleted dish. Restore it first.',
            ], 422);
        }

        $request->validate([
            'file' => 'required|file|mimes:usdz,glb,gltf|max:51200',
            'type' => 'required|in:usdz,glb',
        ]);

        $file = $request->file('file');
        $type = $request->input('type');

        $path = $file->store("dishes/{$dish->id}", 'public');

        $asset = DishAsset::create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => $type,
            'file_path' => $path,
            'glb_path' => $type === 'glb' ? $path : null,
            'usdz_path' => $type === 'usdz' ? $path : null,
            'file_url' => "/storage/{$path}",
            'file_size' => $file->getSize(),
            'mime_type' => $type === 'glb' ? 'model/gltf-binary' : 'model/vnd.usdz+zip',
            'metadata' => [
                'uploaded_at' => now()->toIso8601String(),
                'file_name' => $file->getClientOriginalName(),
            ],
        ]);

        return response()->json($asset, 201);
    }

    public function delete(Request $request, DishAsset $asset)
    {
        $asset->loadMissing('dish.restaurant');
        $ownerRestaurantId = $request->user()?->restaurant?->id;

        if (!$ownerRestaurantId || $asset->dish->restaurant_id !== $ownerRestaurantId) {
            abort(404);
        }

        if ($asset->file_path) {
            Storage::disk('public')->delete($asset->file_path);
        }

        $asset->delete();

        return response()->noContent();
    }

    private function assertDishBelongsToCurrentUser(Request $request, Dish $dish): void
    {
        $dish->loadMissing('restaurant');
        $ownerRestaurantId = $request->user()?->restaurant?->id;

        if (!$ownerRestaurantId || $dish->restaurant_id !== $ownerRestaurantId) {
            abort(404);
        }
    }
}
