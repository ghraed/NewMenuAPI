<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\Restaurant;
use App\Models\DishAsset;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;



class DishController extends Controller
{
    public function index(Request $request)
    {
        $restaurant = Restaurant::findOrFail(1);

        return response()->json(
            $restaurant->dishes()
                ->with('assets')
                ->paginate(15)
        );
    }

    public function store(Request $request)
    {
        Log::info($request->all());
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'category' => 'required|string|max:100',
            'image_url' => 'nullable|url',
            'glb_file' => 'nullable|file|mimes:glb,gltf|max:51200|required_without:usdz_file',
            'usdz_file' => 'nullable|file|mimes:usdz|max:51200|required_without:glb_file',
        ]);

        $user = Auth::user();
        $restaurant = Restaurant::findOrFail(1);
        // !! delete the $dish = ... and replace it with the commented line below when auth user has restaurant relation
        // $dish = $user->restaurant->dishes()->create(
        // add uuid generation
        $validated['uuid'] = Str::uuid();
        $dish = $restaurant->dishes()->create(
            array_merge($validated, ['status' => 'draft'])
        );

        $assets = [];

        if ($request->hasFile('glb_file')) {
            $file = $request->file('glb_file');
            $path = $file->store("dishes/{$dish->id}", 'public');
            Log::info($path);
            $assets[] = DishAsset::create([
                'uuid' => Str::uuid(),
                'dish_id' => $dish->id,
                'asset_type' => 'glb',
                'file_path' => $path,
                'glb_path' => $path,
                'file_url' => "/storage/{$path}",
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'file_name' => $file->getClientOriginalName(),
                ],
            ]);
        }

        if ($request->hasFile('usdz_file')) {
            $file = $request->file('usdz_file');
            $path = $file->store("dishes/{$dish->id}", 'public');

            $assets[] = DishAsset::create([
                'uuid' => Str::uuid(),
                'dish_id' => $dish->id,
                'asset_type' => 'usdz',
                'file_path' => $path,
                'usdz_path' => $path,
                'file_url' => "/storage/{$path}",
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'metadata' => [
                    'uploaded_at' => now()->toIso8601String(),
                    'file_name' => $file->getClientOriginalName(),
                ],
            ]);
        }

        return response()->json($dish->load('assets'), 201);
    }

    public function show(Dish $dish)
    {
        return response()->json($dish->load('assets'));
    }

    public function update(Request $request, Dish $dish)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'category' => 'sometimes|string|max:100',
            'image_url' => 'nullable|url',
        ]);

        $dish->update($validated);

        return response()->json($dish);
    }

    public function destroy(Dish $dish)
    {
        $dish->assets()->delete();
        $dish->delete();

        return response()->noContent();
    }

    public function publish(Dish $dish)
    {
        if (
            !$dish->assets()->where('asset_type', 'glb')->exists() &&
            !$dish->assets()->where('asset_type', 'usdz')->exists()
        ) {
            return response()->json([
                'message' => 'Cannot publish dish without 3D assets'
            ], 422);
        }

        $dish->update(['status' => 'published']);

        return response()->json($dish);
    }

    public function unpublish(Dish $dish)
    {
        $dish->update(['status' => 'draft']);

        return response()->json($dish);
    }
}
