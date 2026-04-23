<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\DishAsset;
use App\Models\Ingredient;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DishController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $includeDeleted = filter_var($request->query('include_deleted', '1'), FILTER_VALIDATE_BOOL);
        $onlyDeleted = filter_var($request->query('only_deleted', '0'), FILTER_VALIDATE_BOOL);
        $perPage = max(1, min((int) $request->query('per_page', 15), 200));

        $query = $restaurant->dishes()->with(['assets', 'latestScan', 'suggestedDishes', 'relatedDishes']);

        if ($onlyDeleted) {
            $query->onlyTrashed();
        } elseif ($includeDeleted) {
            $query->withTrashed();
        }

        return response()->json(
            $query
                ->orderByRaw('deleted_at IS NOT NULL')
                ->orderByDesc('updated_at')
                ->paginate($perPage)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'currency' => 'nullable|in:USD,LBP,SYP',
            'calories' => 'nullable|integer|min:0',
            'category' => 'required|string|max:100',
            'category_ar' => 'nullable|string|max:100',
            'status' => 'nullable|in:draft,published',
            'is_anchor' => 'nullable|boolean',
            'image_url' => 'nullable|url',
            'suggested_dish_ids' => 'sometimes|array',
            'suggested_dish_ids.*' => 'integer',
            'related_dish_ids' => 'sometimes|array',
            'related_dish_ids.*' => 'integer',
            'recipe_ingredients' => 'sometimes|array',
            'recipe_ingredients.*.ingredient_id' => 'required|integer|distinct|exists:ingredients,id',
            'recipe_ingredients.*.quantity_required' => 'required|numeric|gt:0',
            'recipe_ingredients.*.order_index' => 'nullable|integer|min:0',
            'recipe_ingredients.*.show_in_animation' => 'nullable|boolean',
            'glb_file' => [
                'nullable',
                'file',
                'max:51200',
                function ($attribute, $value, $fail) {
                    if ($value && !in_array(strtolower($value->getClientOriginalExtension()), ['glb', 'gltf'], true)) {
                        $fail('The glb file must have a .glb or .gltf extension.');
                    }
                },
            ],
            'usdz_file' => [
                'nullable',
                'file',
                'max:51200',
                function ($attribute, $value, $fail) {
                    if ($value && strtolower($value->getClientOriginalExtension()) !== 'usdz') {
                        $fail('The usdz file must have a .usdz extension.');
                    }
                },
            ],
        ]);

        $restaurant = $this->getRestaurantForRequest($request);

        $validated['uuid'] = (string) Str::uuid();
        $status = $validated['status'] ?? 'published';
        unset($validated['status']);
        $suggestedDishIds = array_values(array_unique(array_map('intval', $validated['suggested_dish_ids'] ?? [])));
        $relatedDishIds = array_values(array_unique(array_map('intval', $validated['related_dish_ids'] ?? [])));
        $recipeIngredients = $validated['recipe_ingredients'] ?? null;
        unset($validated['suggested_dish_ids']);
        unset($validated['related_dish_ids']);
        unset($validated['recipe_ingredients']);
        $dish = DB::transaction(function () use ($restaurant, $validated, $status, $suggestedDishIds, $relatedDishIds, $recipeIngredients) {
            $dish = $restaurant->dishes()->create(
                array_merge($validated, ['status' => $status])
            );

            $this->syncSuggestedDishes($dish, $restaurant, $suggestedDishIds);
            $this->syncRelatedDishes($dish, $restaurant, $relatedDishIds);

            if ($recipeIngredients !== null) {
                $this->syncDishIngredients($dish, $restaurant, $recipeIngredients);
            }

            return $dish;
        });

        if ($request->hasFile('glb_file')) {
            $this->storeUploadedAsset($dish, $request->file('glb_file'), 'glb');
        }

        if ($request->hasFile('usdz_file')) {
            $this->storeUploadedAsset($dish, $request->file('usdz_file'), 'usdz');
        }

        return response()->json($dish->load([
            'assets',
            'latestScan',
            'suggestedDishes.assets',
            'relatedDishes.assets',
            'dishIngredients.ingredient',
        ]), 201);
    }

    public function show(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        return response()->json($dish->load([
            'assets',
            'latestScan',
            'suggestedDishes.assets',
            'relatedDishes.assets',
            'dishIngredients.ingredient',
        ]));
    }

    public function copyModel(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if ($dish->trashed()) {
            return response()->json([
                'message' => 'Cannot copy a model onto a deleted dish. Restore it first.',
            ], 422);
        }

        $validated = $request->validate([
            'source_dish_id' => ['required', 'integer', 'exists:dishes,id'],
        ]);

        $sourceDish = Dish::query()
            ->with('assets')
            ->findOrFail((int) $validated['source_dish_id']);
        $this->assertDishBelongsToRestaurant($sourceDish, $restaurant);

        if ($sourceDish->trashed()) {
            return response()->json([
                'message' => 'Cannot copy a model from a deleted dish.',
            ], 422);
        }

        if ($sourceDish->id === $dish->id) {
            return response()->json([
                'message' => 'Choose a different dish as the model source.',
            ], 422);
        }

        $sourceAssets = $sourceDish->assets
            ->whereIn('asset_type', ['glb', 'usdz'])
            ->keyBy('asset_type');

        if (! $sourceAssets->has('glb')) {
            return response()->json([
                'message' => 'The selected source dish does not have a reusable 3D model yet.',
            ], 422);
        }

        foreach (['glb', 'usdz'] as $assetType) {
            $this->clearDishAssetType($dish, $assetType);

            $sourceAsset = $sourceAssets->get($assetType);
            if (! $sourceAsset) {
                continue;
            }

            $this->copyAssetToDish($dish, $sourceAsset);
        }

        return response()->json($dish->fresh()->load(['assets', 'latestScan']));
    }

    public function update(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if ($dish->trashed()) {
            return response()->json([
                'message' => 'Cannot update a deleted dish. Restore it first.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'currency' => 'sometimes|in:USD,LBP,SYP',
            'calories' => 'nullable|integer|min:0',
            'category' => 'sometimes|string|max:100',
            'category_ar' => 'nullable|string|max:100',
            'status' => 'sometimes|in:draft,published',
            'is_anchor' => 'sometimes|boolean',
            'image_url' => 'nullable|url',
            'suggested_dish_ids' => 'sometimes|array',
            'suggested_dish_ids.*' => 'integer',
            'related_dish_ids' => 'sometimes|array',
            'related_dish_ids.*' => 'integer',
            'recipe_ingredients' => 'sometimes|array',
            'recipe_ingredients.*.ingredient_id' => 'required|integer|distinct|exists:ingredients,id',
            'recipe_ingredients.*.quantity_required' => 'required|numeric|gt:0',
            'recipe_ingredients.*.order_index' => 'nullable|integer|min:0',
            'recipe_ingredients.*.show_in_animation' => 'nullable|boolean',
        ]);

        $suggestedDishIds = null;
        if (array_key_exists('suggested_dish_ids', $validated)) {
            $suggestedDishIds = array_values(array_unique(array_map('intval', $validated['suggested_dish_ids'] ?? [])));
            unset($validated['suggested_dish_ids']);
        }

        $relatedDishIds = null;
        if (array_key_exists('related_dish_ids', $validated)) {
            $relatedDishIds = array_values(array_unique(array_map('intval', $validated['related_dish_ids'] ?? [])));
            unset($validated['related_dish_ids']);
        }

        $recipeIngredients = null;
        if (array_key_exists('recipe_ingredients', $validated)) {
            $recipeIngredients = $validated['recipe_ingredients'] ?? [];
            unset($validated['recipe_ingredients']);
        }

        DB::transaction(function () use ($dish, $validated, $restaurant, $suggestedDishIds, $relatedDishIds, $recipeIngredients): void {
            $dish->update($validated);

            if ($suggestedDishIds !== null) {
                $this->syncSuggestedDishes($dish, $restaurant, $suggestedDishIds);
            }

            if ($relatedDishIds !== null) {
                $this->syncRelatedDishes($dish, $restaurant, $relatedDishIds);
            }

            if ($recipeIngredients !== null) {
                $this->syncDishIngredients($dish, $restaurant, $recipeIngredients);
            }
        });

        return response()->json($dish->load([
            'assets',
            'latestScan',
            'suggestedDishes.assets',
            'relatedDishes.assets',
            'dishIngredients.ingredient',
        ]));
    }

    public function destroy(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if ($dish->trashed()) {
            return response()->json([
                'message' => 'Dish is already deleted.',
                'deleted_at' => $dish->deleted_at,
                'model_cleanup_at' => $this->cleanupAt($dish->deleted_at),
            ], 409);
        }

        $dish->delete();

        return response()->json([
            'message' => 'Dish moved to deleted state. Model files will be removed after 7 days if not restored or permanently deleted.',
            'deleted_at' => $dish->fresh()->deleted_at,
            'model_cleanup_at' => $this->cleanupAt($dish->fresh()->deleted_at),
        ]);
    }

    public function restore(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if (!$dish->trashed()) {
            return response()->json([
                'message' => 'Dish is already active.',
            ], 422);
        }

        $dish->restore();

            return response()->json([
                'message' => 'Dish restored successfully.',
                'dish' => $dish->fresh()->load(['assets', 'latestScan']),
            ]);
    }

    public function forceDelete(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if (!$dish->trashed()) {
            return response()->json([
                'message' => 'Soft delete the dish before permanently deleting it.',
            ], 422);
        }

        $dish->loadMissing('assets');
        $this->deleteDishAssets($dish);
        $dish->forceDelete();

        return response()->json([
            'message' => 'Dish permanently deleted. This action cannot be undone.',
        ]);
    }

    public function publish(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if ($dish->trashed()) {
            return response()->json([
                'message' => 'Cannot publish a deleted dish. Restore it first.',
            ], 422);
        }

        $dish->update(['status' => 'published']);

        return response()->json($dish->load(['assets', 'latestScan']));
    }

    public function unpublish(Request $request, Dish $dish): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertDishBelongsToRestaurant($dish, $restaurant);

        if ($dish->trashed()) {
            return response()->json([
                'message' => 'Cannot unpublish a deleted dish. Restore it first.',
            ], 422);
        }

        $dish->update(['status' => 'draft']);

        return response()->json($dish->load(['assets', 'latestScan']));
    }

    private function deleteDishAssets(Dish $dish): void
    {
        foreach ($dish->assets as $asset) {
            $this->deleteStoredAssetFile($asset);
            $asset->delete();
        }
    }

    private function clearDishAssetType(Dish $dish, string $assetType): void
    {
        $dish->assets()->where('asset_type', $assetType)->get()->each(function (DishAsset $existingAsset): void {
            $this->deleteStoredAssetFile($existingAsset);
            $existingAsset->delete();
        });
    }

    private function storeUploadedAsset(Dish $dish, \Illuminate\Http\UploadedFile $file, string $type): DishAsset
    {
        $originalName = basename((string) $file->getClientOriginalName());
        if ($originalName === '') {
            $originalName = $type === 'usdz' ? 'model.usdz' : 'model.glb';
        }

        $path = $file->storeAs("dishes/{$dish->id}", $originalName, 'public');

        $dish->assets()->where('asset_type', $type)->get()->each(function (DishAsset $existingAsset): void {
            $this->deleteStoredAssetFile($existingAsset);
            $existingAsset->delete();
        });

        $asset = DishAsset::create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => $type,
            'storage_disk' => 'public',
            'file_path' => $path,
            'glb_path' => $type === 'glb' ? $path : null,
            'usdz_path' => $type === 'usdz' ? $path : null,
            'file_url' => '',
            'file_size' => $file->getSize(),
            'mime_type' => $type === 'glb' ? 'model/gltf-binary' : 'model/vnd.usdz+zip',
            'metadata' => [
                'uploaded_at' => now()->toIso8601String(),
                'file_name' => $file->getClientOriginalName(),
            ],
        ]);

        $asset->update([
            'file_url' => route('api.assets.show', ['asset' => $asset->id], false),
        ]);

        return $asset;
    }

    private function copyAssetToDish(Dish $dish, DishAsset $sourceAsset): DishAsset
    {
        $sourcePath = $sourceAsset->file_path;
        if (! $sourcePath) {
            throw new RuntimeException('Source asset file path is missing.');
        }

        $disk = $sourceAsset->storage_disk ?: 'public';
        $storage = Storage::disk($disk);
        $fileName = $this->resolveAssetFileName($sourceAsset);
        $destinationPath = "dishes/{$dish->id}/{$sourceAsset->asset_type}-".Str::uuid()."-{$fileName}";

        if (! $storage->copy($sourcePath, $destinationPath)) {
            throw new RuntimeException('Failed to copy the selected model asset.');
        }

        $asset = DishAsset::create([
            'uuid' => (string) Str::uuid(),
            'dish_id' => $dish->id,
            'asset_type' => $sourceAsset->asset_type,
            'storage_disk' => $disk,
            'file_path' => $destinationPath,
            'glb_path' => $sourceAsset->asset_type === 'glb' ? $destinationPath : null,
            'usdz_path' => $sourceAsset->asset_type === 'usdz' ? $destinationPath : null,
            'file_url' => '',
            'file_size' => $sourceAsset->file_size,
            'mime_type' => $sourceAsset->mime_type,
            'metadata' => array_filter([
                ...($sourceAsset->metadata ?? []),
                'source' => 'copied_from_existing_model',
                'source_dish_id' => $sourceAsset->dish_id,
                'copied_at' => now()->toIso8601String(),
                'file_name' => $fileName,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]);

        $asset->update([
            'file_url' => route('api.assets.show', ['asset' => $asset->id], false),
        ]);

        return $asset;
    }

    private function resolveAssetFileName(DishAsset $asset): string
    {
        $metaFileName = $asset->metadata['file_name'] ?? null;
        if (is_string($metaFileName) && trim($metaFileName) !== '') {
            return basename($metaFileName);
        }

        $pathName = basename((string) $asset->file_path);
        if ($pathName !== '') {
            return $pathName;
        }

        return match ($asset->asset_type) {
            'usdz' => 'model.usdz',
            'preview_image' => 'preview.jpg',
            default => 'model.glb',
        };
    }

    private function cleanupAt(?Carbon $deletedAt): ?string
    {
        return $deletedAt?->copy()->addDays(7)->toIso8601String();
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
            // Keep DB cleanup resilient if the backing file is already missing.
        }
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

    private function syncSuggestedDishes(Dish $dish, Restaurant $restaurant, array $suggestedDishIds): void
    {
        $validIds = $this->validateRelatedDishIds(
            $dish,
            $restaurant,
            $suggestedDishIds,
            'suggested_dish_ids',
            'Suggested dishes must belong to the same restaurant and cannot be deleted.'
        );

        $dish->suggestedDishes()->sync($validIds);
    }

    private function syncRelatedDishes(Dish $dish, Restaurant $restaurant, array $relatedDishIds): void
    {
        $validIds = $this->validateRelatedDishIds(
            $dish,
            $restaurant,
            $relatedDishIds,
            'related_dish_ids',
            'Related dishes must belong to the same restaurant and cannot be deleted.'
        );

        $dish->relatedDishes()->sync($validIds);
    }

    private function syncDishIngredients(Dish $dish, Restaurant $restaurant, array $recipeIngredients): void
    {
        $normalizedRows = [];

        foreach ($recipeIngredients as $index => $row) {
            $ingredientId = (int) ($row['ingredient_id'] ?? 0);
            $quantity = round((float) ($row['quantity_required'] ?? 0), 3);

            if ($ingredientId <= 0) {
                throw ValidationException::withMessages([
                    "recipe_ingredients.{$index}.ingredient_id" => 'Select a valid ingredient.',
                ]);
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "recipe_ingredients.{$index}.quantity_required" => 'Quantity required must be greater than zero.',
                ]);
            }

            $normalizedRows[] = [
                'ingredient_id' => $ingredientId,
                'quantity' => $quantity,
                'order_index' => (int) ($row['order_index'] ?? $index),
                'show_in_animation' => array_key_exists('show_in_animation', $row)
                    ? filter_var($row['show_in_animation'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                    : true,
            ];
        }

        if ($normalizedRows === []) {
            $dish->dishIngredients()->delete();

            return;
        }

        $ingredientIds = array_values(array_unique(array_map(
            fn (array $row): int => $row['ingredient_id'],
            $normalizedRows
        )));

        $ingredientsById = Ingredient::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('id', $ingredientIds)
            ->get()
            ->keyBy('id');

        if ($ingredientsById->count() !== count($ingredientIds)) {
            throw ValidationException::withMessages([
                'recipe_ingredients' => 'Recipe ingredients must belong to your restaurant.',
            ]);
        }

        $normalizedByIngredientId = collect($normalizedRows)->keyBy('ingredient_id');
        $existingRows = $dish->dishIngredients()->get()->keyBy('ingredient_id');
        $ingredientIdsToDelete = $existingRows->keys()->diff($normalizedByIngredientId->keys())->values()->all();

        if ($ingredientIdsToDelete !== []) {
            $dish->dishIngredients()
                ->whereIn('ingredient_id', $ingredientIdsToDelete)
                ->delete();
        }

        foreach ($normalizedRows as $row) {
            $ingredientId = $row['ingredient_id'];
            /** @var Ingredient $ingredient */
            $ingredient = $ingredientsById->get($ingredientId);

            $payload = [
                'ingredient_id' => $ingredientId,
                'quantity' => $row['quantity'],
                'unit' => $ingredient->stock_unit,
                'order_index' => $row['order_index'],
                'show_in_animation' => $row['show_in_animation'],
            ];

            $existingRow = $existingRows->get($ingredientId);
            if ($existingRow) {
                $existingRow->update($payload);

                continue;
            }

            $dish->dishIngredients()->create($payload);
        }
    }

    private function validateRelatedDishIds(
        Dish $dish,
        Restaurant $restaurant,
        array $dishIds,
        string $field,
        string $message
    ): array {
        $filteredIds = array_values(array_unique(array_filter(
            $dishIds,
            fn (int $candidateDishId) => $candidateDishId !== $dish->id
        )));

        if ($filteredIds === []) {
            return [];
        }

        $validIds = Dish::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('deleted_at')
            ->whereIn('id', $filteredIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($validIds) !== count($filteredIds)) {
            throw ValidationException::withMessages([
                $field => $message,
            ]);
        }

        return $validIds;
    }
}
