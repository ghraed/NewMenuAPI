<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryIngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $ingredients = Ingredient::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderByRaw('is_active desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'ingredients' => $ingredients->map(fn (Ingredient $ingredient) => $this->formatIngredient($ingredient))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ingredients', 'name')->where('restaurant_id', $restaurant->id),
            ],
            'unit' => ['required', 'string', 'in:'.implode(',', Ingredient::stockUnits())],
            'current_quantity' => ['required', 'numeric', 'min:0'],
            'low_stock_threshold' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $initialQuantity = round((float) $validated['current_quantity'], 3);

        $ingredient = DB::transaction(function () use ($request, $restaurant, $validated, $initialQuantity) {
            $ingredient = Ingredient::query()->create([
                'uuid' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'name' => trim($validated['name']),
                'name_ar' => null,
                'stock_unit' => $validated['unit'],
                'current_stock_quantity' => $initialQuantity,
                'low_stock_threshold' => round((float) $validated['low_stock_threshold'], 3),
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'storage_disk' => 'public',
                'file_path' => null,
                'source_file_name' => null,
                'file_size' => null,
                'mime_type' => null,
            ]);

            if ($initialQuantity > 0) {
                $this->createStockMovement(
                    ingredient: $ingredient,
                    movementType: StockMovement::TYPE_OPENING_BALANCE,
                    quantityDelta: $initialQuantity,
                    quantityBefore: 0,
                    quantityAfter: $initialQuantity,
                    performedByUserId: $request->user()?->id,
                    notes: 'Initial quantity set during ingredient creation.'
                );
            }

            return $ingredient;
        });

        return response()->json([
            'message' => 'Ingredient created successfully.',
            'ingredient' => $this->formatIngredient($ingredient->fresh()),
        ], 201);
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $ingredient = $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('ingredients', 'name')
                    ->where('restaurant_id', $restaurant->id)
                    ->ignore($ingredient->id),
            ],
            'unit' => ['required', 'string', 'in:'.implode(',', Ingredient::stockUnits())],
            'low_stock_threshold' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (
            $ingredient->stock_unit !== $validated['unit']
            && $ingredient->dishIngredients()->exists()
        ) {
            throw ValidationException::withMessages([
                'unit' => __('messages.inventory.unit_change_blocked'),
            ]);
        }

        $ingredient->update([
            'name' => trim($validated['name']),
            'stock_unit' => $validated['unit'],
            'low_stock_threshold' => round((float) $validated['low_stock_threshold'], 3),
            'is_active' => (bool) $validated['is_active'],
        ]);

        return response()->json([
            'message' => 'Ingredient updated successfully.',
            'ingredient' => $this->formatIngredient($ingredient->fresh()),
        ]);
    }

    public function activate(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $ingredient = $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $ingredient->update(['is_active' => true]);

        return response()->json([
            'message' => 'Ingredient activated successfully.',
            'ingredient' => $this->formatIngredient($ingredient->fresh()),
        ]);
    }

    public function deactivate(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $ingredient = $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $usedByPublishedDishes = $ingredient->dishIngredients()
            ->whereHas('dish', fn ($query) => $query->where('status', 'published'))
            ->exists();

        if ($usedByPublishedDishes) {
            throw ValidationException::withMessages([
                'ingredient' => __('messages.inventory.deactivate_blocked_published_dishes'),
            ]);
        }

        $ingredient->update(['is_active' => false]);

        return response()->json([
            'message' => 'Ingredient deactivated successfully.',
            'ingredient' => $this->formatIngredient($ingredient->fresh()),
        ]);
    }

    public function restock(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $ingredient = $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $restockQuantity = round((float) $validated['quantity'], 3);

        $ingredient = DB::transaction(function () use ($request, $ingredient, $validated, $restockQuantity) {
            /** @var Ingredient $lockedIngredient */
            $lockedIngredient = Ingredient::query()
                ->whereKey($ingredient->id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = round((float) $lockedIngredient->current_stock_quantity, 3);
            $quantityAfter = round($quantityBefore + $restockQuantity, 3);

            $lockedIngredient->update([
                'current_stock_quantity' => $quantityAfter,
            ]);

            $this->createStockMovement(
                ingredient: $lockedIngredient,
                movementType: StockMovement::TYPE_RESTOCK,
                quantityDelta: $restockQuantity,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                performedByUserId: $request->user()?->id,
                reference: $validated['reference'] ?? null,
                notes: $validated['notes'] ?? null
            );

            return $lockedIngredient;
        });

        return response()->json([
            'message' => 'Ingredient restocked successfully.',
            'ingredient' => $this->formatIngredient($ingredient->fresh()),
        ]);
    }

    public function adjust(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $ingredient = $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $validated = $request->validate([
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $quantityDelta = round((float) $validated['quantity_delta'], 3);

        $ingredient = DB::transaction(function () use ($request, $ingredient, $validated, $quantityDelta) {
            /** @var Ingredient $lockedIngredient */
            $lockedIngredient = Ingredient::query()
                ->whereKey($ingredient->id)
                ->lockForUpdate()
                ->firstOrFail();

            $quantityBefore = round((float) $lockedIngredient->current_stock_quantity, 3);
            $quantityAfter = round($quantityBefore + $quantityDelta, 3);

            if ($quantityAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity_delta' => 'Adjustment would make stock negative.',
                ]);
            }

            $lockedIngredient->update([
                'current_stock_quantity' => $quantityAfter,
            ]);

            $this->createStockMovement(
                ingredient: $lockedIngredient,
                movementType: StockMovement::TYPE_MANUAL_ADJUSTMENT,
                quantityDelta: $quantityDelta,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                performedByUserId: $request->user()?->id,
                reference: $validated['reference'] ?? null,
                notes: $validated['notes'] ?? null
            );

            return $lockedIngredient;
        });

        return response()->json([
            'message' => 'Ingredient quantity adjusted successfully.',
            'ingredient' => $this->formatIngredient($ingredient->fresh()),
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user?->loadMissing('restaurant');

        if (! $user?->restaurant) {
            abort(403, 'No restaurant is linked to this account.');
        }

        return $user->restaurant;
    }

    private function assertIngredientBelongsToRestaurant(Ingredient $ingredient, Restaurant $restaurant): Ingredient
    {
        if ((int) $ingredient->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }

        return $ingredient;
    }

    private function createStockMovement(
        Ingredient $ingredient,
        string $movementType,
        float $quantityDelta,
        float $quantityBefore,
        float $quantityAfter,
        ?int $performedByUserId,
        ?string $reference = null,
        ?string $notes = null
    ): void {
        StockMovement::query()->create([
            'restaurant_id' => $ingredient->restaurant_id,
            'ingredient_id' => $ingredient->id,
            'performed_by' => $performedByUserId,
            'movement_type' => $movementType,
            'unit' => $ingredient->stock_unit,
            'quantity_delta' => round($quantityDelta, 3),
            'quantity_before' => round($quantityBefore, 3),
            'quantity_after' => round($quantityAfter, 3),
            'ingredient_name_snapshot' => $ingredient->name,
            'reference' => $reference,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }

    private function formatIngredient(Ingredient $ingredient): array
    {
        $currentQuantity = (float) $ingredient->current_stock_quantity;
        $lowStockThreshold = (float) $ingredient->low_stock_threshold;

        return [
            'id' => $ingredient->id,
            'uuid' => $ingredient->uuid,
            'name' => $ingredient->name,
            'name_ar' => $ingredient->name_ar,
            'unit' => $ingredient->stock_unit,
            'current_quantity' => $this->formatQuantity($currentQuantity),
            'low_stock_threshold' => $this->formatQuantity($lowStockThreshold),
            'is_active' => (bool) $ingredient->is_active,
            'is_low_stock' => $ingredient->is_active && $currentQuantity <= $lowStockThreshold,
            'created_at' => $ingredient->created_at?->toIso8601String(),
            'updated_at' => $ingredient->updated_at?->toIso8601String(),
        ];
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
