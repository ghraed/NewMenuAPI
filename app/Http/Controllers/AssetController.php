<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Ingredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AssetController extends Controller
{
    public function upload(Request $request, Dish $dish): JsonResponse
    {
        $this->assertDishBelongsToCurrentUser($request, $dish);

        if ($dish->trashed()) {
            return response()->json([
                'message' => 'Cannot upload assets to a deleted dish. Restore it first.',
            ], 422);
        }

        $request->validate([
            'file' => [
                'nullable',
                'file',
                'max:51200',
                function ($attribute, $value, $fail) use ($request) {
                    if ($value === null) {
                        return;
                    }

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
                        return;
                    }

                    if (in_array($type, ['preview_image', 'ingredient_image'], true) && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true)) {
                        $fail('The file field must be an image of type: jpg, jpeg, png, webp, heic, heif.');
                    }
                },
            ],
            'type' => 'required|in:usdz,glb,preview_image,ingredient_image',
            'label' => 'nullable|string|max:120',
            'quantity' => 'nullable|string|max:60',
            'order_index' => 'nullable|integer|min:0|max:999',
            'ingredient_library_id' => 'nullable|integer|min:1',
        ]);

        $file = $request->file('file');
        $type = $request->input('type');
        $sourceIngredient = $type === 'ingredient_image'
            ? $this->resolveIngredientLibrarySelection($request, $dish, $request->input('ingredient_library_id'))
            : null;

        if (! $file && ! $sourceIngredient) {
            return response()->json([
                'message' => $type === 'ingredient_image'
                    ? 'Ingredient image uploads require either a file or a saved ingredient selection.'
                    : 'The file field is required.',
            ], 422);
        }

        if ($type === 'ingredient_image' && ! $sourceIngredient && $this->normalizeOptionalString($request->input('label')) === null) {
            return response()->json([
                'message' => 'Ingredient image uploads require a label.',
            ], 422);
        }

        $originalName = $this->resolveOriginalName($file, $type, $sourceIngredient);

        if ($originalName === '') {
            $originalName = match ($type) {
                'usdz' => 'model.usdz',
                'preview_image' => 'preview.jpg',
                'ingredient_image' => 'ingredient.jpg',
                default => 'model.glb',
            };
        }

        if ($this->replacesExistingAsset($type)) {
            $existingAssets = $dish->assets()->where('asset_type', $type)->get();

            foreach ($existingAssets as $existingAsset) {
                $this->deleteStoredAssetFile($existingAsset);
                $existingAsset->delete();
            }
        }

        $path = $type === 'ingredient_image'
            ? $this->storeIngredientAssetFile($dish, $originalName, $file, $sourceIngredient)
            : $file->storeAs("dishes/{$dish->id}", $originalName, 'public');

        $asset = DishAsset::create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => $type,
            'storage_disk' => 'public',
            'file_path' => $path,
            'glb_path' => $type === 'glb' ? $path : null,
            'usdz_path' => $type === 'usdz' ? $path : null,
            'file_url' => '',
            'file_size' => $file?->getSize() ?? $sourceIngredient?->file_size ?? 0,
            'mime_type' => $this->resolveMimeType($file, $type, $sourceIngredient),
            'metadata' => $this->buildAssetMetadata($request, $file, $type, $sourceIngredient),
        ]);

        $asset->update([
            'file_url' => route('api.assets.show', ['asset' => $asset->id], false),
        ]);

        return response()->json($asset, 201);
    }

    public function update(Request $request, DishAsset $asset): JsonResponse
    {
        $this->assertAssetBelongsToCurrentUser($request, $asset);

        if ($asset->asset_type !== 'ingredient_image') {
            return response()->json([
                'message' => 'Only ingredient images support label updates.',
            ], 422);
        }

        $validated = $request->validate([
            'label' => 'required|string|max:120',
            'quantity' => 'nullable|string|max:60',
            'order_index' => 'nullable|integer|min:0|max:999',
            'ingredient_library_id' => 'nullable|integer|min:1',
        ]);

        $ingredientLibraryId = $this->normalizeOptionalInteger($validated['ingredient_library_id'] ?? null);
        if ($ingredientLibraryId !== null) {
            $this->assertIngredientBelongsToCurrentRestaurant($request, $ingredientLibraryId);
        }

        $metadata = is_array($asset->metadata) ? $asset->metadata : [];
        $metadata['label'] = trim((string) $validated['label']);
        $metadata['quantity'] = $this->normalizeOptionalString($validated['quantity'] ?? null);
        $metadata['order_index'] = (int) ($validated['order_index'] ?? 0);
        $metadata['ingredient_library_id'] = $ingredientLibraryId;

        $asset->update([
            'metadata' => $metadata,
        ]);

        return response()->json($asset->fresh());
    }

    public function delete(Request $request, DishAsset $asset): JsonResponse
    {
        $this->assertAssetBelongsToCurrentUser($request, $asset);

        $this->deleteStoredAssetFile($asset);
        $asset->delete();

        return response()->noContent();
    }

    private function assertDishBelongsToCurrentUser(Request $request, Dish $dish): void
    {
        $dish->loadMissing('restaurant');
        $request->user()?->loadMissing('restaurant', 'staffRestaurants');
        $ownerRestaurantId = $request->user()?->currentRestaurant()?->id;

        if (!$ownerRestaurantId || $dish->restaurant_id !== $ownerRestaurantId) {
            abort(404);
        }
    }

    private function assertAssetBelongsToCurrentUser(Request $request, DishAsset $asset): void
    {
        $asset->loadMissing('dish.restaurant');
        $request->user()?->loadMissing('restaurant', 'staffRestaurants');
        $ownerRestaurantId = $request->user()?->currentRestaurant()?->id;

        if (!$ownerRestaurantId || $asset->dish->restaurant_id !== $ownerRestaurantId) {
            abort(404);
        }
    }

    private function deleteStoredAssetFile(DishAsset $asset): void
    {
        if (! $asset->file_path) {
            return;
        }

        $disk = $asset->storage_disk ?: 'public';

        try {
            Storage::disk($disk)->delete($asset->file_path);
        } catch (\Throwable) {
            // Best-effort cleanup; keep DB deletion successful if file is already gone.
        }
    }

    private function resolveMimeType(?\Illuminate\Http\UploadedFile $file, string $type, ?Ingredient $sourceIngredient = null): string
    {
        if ($type === 'glb') {
            return 'model/gltf-binary';
        }

        if ($type === 'usdz') {
            return 'model/vnd.usdz+zip';
        }

        return $file?->getMimeType() ?: $sourceIngredient?->mime_type ?: 'image/jpeg';
    }

    private function replacesExistingAsset(string $type): bool
    {
        return in_array($type, ['glb', 'usdz', 'preview_image'], true);
    }

    private function buildAssetMetadata(
        Request $request,
        ?\Illuminate\Http\UploadedFile $file,
        string $type,
        ?Ingredient $sourceIngredient = null
    ): array
    {
        $metadata = [
            'uploaded_at' => now()->toIso8601String(),
            'file_name' => $file?->getClientOriginalName()
                ?: $sourceIngredient?->source_file_name
                ?: ($sourceIngredient?->file_path ? basename($sourceIngredient->file_path) : 'asset'),
        ];

        if ($type !== 'ingredient_image') {
            return $metadata;
        }

        $metadata['label'] = $sourceIngredient?->name ?: trim((string) $request->input('label'));
        $metadata['quantity'] = $this->normalizeOptionalString($request->input('quantity'));
        $metadata['order_index'] = (int) $request->input('order_index', 0);
        $metadata['ingredient_library_id'] = $sourceIngredient?->id ?? $this->normalizeOptionalInteger($request->input('ingredient_library_id'));

        return $metadata;
    }

    private function resolveIngredientLibrarySelection(Request $request, Dish $dish, mixed $ingredientLibraryId): ?Ingredient
    {
        $normalizedId = $this->normalizeOptionalInteger($ingredientLibraryId);

        if ($normalizedId === null) {
            return null;
        }

        $ingredient = Ingredient::query()
            ->whereKey($normalizedId)
            ->where('restaurant_id', $dish->restaurant_id)
            ->first();

        if (! $ingredient) {
            abort(404);
        }

        return $ingredient;
    }

    private function assertIngredientBelongsToCurrentRestaurant(Request $request, int $ingredientId): void
    {
        $ownerRestaurantId = $request->user()?->restaurant?->id;

        if (! $ownerRestaurantId) {
            abort(404);
        }

        $ingredientExists = Ingredient::query()
            ->whereKey($ingredientId)
            ->where('restaurant_id', $ownerRestaurantId)
            ->exists();

        if (! $ingredientExists) {
            abort(404);
        }
    }

    private function resolveOriginalName(
        ?\Illuminate\Http\UploadedFile $file,
        string $type,
        ?Ingredient $sourceIngredient = null
    ): string {
        if ($file) {
            return basename((string) $file->getClientOriginalName());
        }

        if ($sourceIngredient?->source_file_name) {
            return basename($sourceIngredient->source_file_name);
        }

        if ($sourceIngredient?->file_path) {
            return basename($sourceIngredient->file_path);
        }

        return match ($type) {
            'usdz' => 'model.usdz',
            'preview_image' => 'preview.jpg',
            'ingredient_image' => 'ingredient.jpg',
            default => 'model.glb',
        };
    }

    private function storeIngredientAssetFile(
        Dish $dish,
        string $originalName,
        ?\Illuminate\Http\UploadedFile $file,
        ?Ingredient $sourceIngredient = null
    ): string {
        $path = "dishes/{$dish->id}/ingredients/".Str::uuid().'-'.$originalName;

        if ($file) {
            return $file->storeAs("dishes/{$dish->id}/ingredients", basename($path), 'public');
        }

        if (! $sourceIngredient?->file_path) {
            throw new \RuntimeException('Missing source ingredient file.');
        }

        $sourceDisk = $sourceIngredient->storage_disk ?: 'public';
        $contents = Storage::disk($sourceDisk)->get($sourceIngredient->file_path);
        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
