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
            'file' => [
                'required',
                'file',
                'max:51200',
                function ($attribute, $value, $fail) use ($request) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    $type = $request->input('type');

                    if ($type === 'glb') {
                        if (!in_array($ext, ['glb', 'gltf'], true)) {
                            $fail('The file field must be a file of type: glb, gltf.');
                        }

                        return;
                    }

                    if ($type === 'usdz' && $ext !== 'usdz') {
                        $fail('The file field must be a file of type: usdz.');
                    }
                },
            ],
            'type' => 'required|in:usdz,glb',
        ]);

        $file = $request->file('file');
        $type = $request->input('type');

        $originalName = basename((string) $file->getClientOriginalName());

        if ($originalName === '') {
            $originalName = $type === 'usdz' ? 'model.usdz' : 'model.glb';
        }

        $existingAssets = $dish->assets()->where('asset_type', $type)->get();

        foreach ($existingAssets as $existingAsset) {
            if ($existingAsset->file_path) {
                Storage::disk('public')->delete($existingAsset->file_path);
            }

            $existingAsset->delete();
        }

        $path = $file->storeAs("dishes/{$dish->id}", $originalName, 'public');

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
