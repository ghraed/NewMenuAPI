<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\DishAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    private $allowedTypes = ['usdz', 'glb', 'preview_image'];
    private $maxFileSize = 50 * 1024 * 1024; // 50MB
    private $mimeTypes = [
        'usdz' => 'model/vnd.usdz+zip',
        'glb' => 'model/gltf-binary',
        'preview_image' => ['image/jpeg', 'image/png', 'image/webp']
    ];

    public function upload(Request $request, Dish $dish)
    {
        $request->validate([
            'file' => 'required|file|mimes:usdz,glb,gltf|max:51200', // 50MB max
            'type' => 'required|in:usdz,glb',
        ]);

        $file = $request->file('file');
        $type = $request->input('type');

        // Store in storage/app/public/dishes/{dish_id}/
        $path = $file->store("dishes/{$dish->id}", 'public');

        // Create asset record
        $asset = DishAsset::create([
            'dish_id' => $dish->id,
            'asset_type' => $type,
            'file_path' => $path,
            'file_url' => asset("storage/{$path}"), // Full URL
            'file_size' => $file->getSize(),
        ]);

        return response()->json($asset);
    }

    public function delete(DishAsset $asset)
    {
        $this->authorize('update', $asset->dish);

        Storage::disk('s3')->delete($asset->file_path);
        $asset->delete();

        return response()->noContent();
    }

    private function extractMetadata($file, $assetType)
    {
        $metadata = [
            'uploaded_at' => now()->toIso8601String(),
            'file_name' => $file->getClientOriginalName(),
        ];

        // For 3D models, extract basic info
        if (in_array($assetType, ['usdz', 'glb'])) {
            $metadata['format'] = $assetType;
            $metadata['size_mb'] = round($file->getSize() / (1024 * 1024), 2);
        }

        return $metadata;
    }
}
