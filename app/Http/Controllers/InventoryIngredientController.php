<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Services\GlobalIngredientProvisioningService;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryIngredientController extends Controller
{
    public function __construct(
        private readonly GlobalIngredientProvisioningService $globalIngredientProvisioningService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->globalIngredientProvisioningService->provisionForRestaurant($restaurant);

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
            'target_quantity' => ['sometimes', 'numeric', 'min:0'],
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
                'target_quantity' => round((float) ($validated['target_quantity'] ?? $validated['low_stock_threshold']), 3),
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
            'target_quantity' => ['sometimes', 'numeric', 'min:0'],
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
            'target_quantity' => round((float) ($validated['target_quantity'] ?? $validated['low_stock_threshold']), 3),
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
            'create_expense' => ['sometimes', 'boolean'],
            'expense_category_id' => [
                'sometimes',
                'integer',
                Rule::exists('expense_categories', 'id')->where('restaurant_id', $restaurant->id),
            ],
            'expense_vendor_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('vendors', 'id')->where('restaurant_id', $restaurant->id),
            ],
            'expense_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'expense_tax_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'expense_currency' => ['sometimes', 'string', 'size:3'],
            'expense_status' => ['sometimes', Rule::in([
                Expense::STATUS_DRAFT,
                Expense::STATUS_APPROVED,
                Expense::STATUS_PAID,
                Expense::STATUS_VOID,
            ])],
            'expense_payment_method' => ['sometimes', 'nullable', Rule::in(['cash', 'card', 'bank_transfer', 'wallet', 'other'])],
            'expense_due_date' => ['sometimes', 'nullable', 'date'],
            'expense_paid_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $restockQuantity = round((float) $validated['quantity'], 3);
        $expenseDraftInput = $this->prepareExpenseLinkInput($validated);
        $status = 'unlinked';
        $warning = null;
        $linkedExpense = null;

        $restockResult = DB::transaction(function () use ($request, $ingredient, $validated, $restockQuantity, $expenseDraftInput) {
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

            $movementCostCents = $expenseDraftInput['can_create']
                ? (int) $expenseDraftInput['amount_cents'] + (int) $expenseDraftInput['tax_amount_cents']
                : null;
            $movementUnitCostCents = (
                $movementCostCents !== null
                && $restockQuantity > 0
            ) ? (int) round($movementCostCents / $restockQuantity) : null;

            if ($movementUnitCostCents !== null && $expenseDraftInput['currency']) {
                $this->updateIngredientCostProfile(
                    ingredient: $lockedIngredient,
                    quantityBefore: $quantityBefore,
                    restockQuantity: $restockQuantity,
                    unitCostCents: $movementUnitCostCents,
                    currency: (string) $expenseDraftInput['currency']
                );
            }

            $movement = $this->createStockMovement(
                ingredient: $lockedIngredient,
                movementType: StockMovement::TYPE_RESTOCK,
                quantityDelta: $restockQuantity,
                quantityBefore: $quantityBefore,
                quantityAfter: $quantityAfter,
                performedByUserId: $request->user()?->id,
                reference: $validated['reference'] ?? null,
                notes: $validated['notes'] ?? null,
                linkedExpenseId: null,
                unitCostCents: $movementUnitCostCents,
                totalCostCents: $movementCostCents
            );

            return [
                'ingredient' => $lockedIngredient,
                'movement' => $movement,
                'quantity_before' => $quantityBefore,
            ];
        });

        $ingredient = $restockResult['ingredient'];
        $movement = $restockResult['movement'];
        $quantityBefore = (float) $restockResult['quantity_before'];

        if ($expenseDraftInput['wants_link']) {
            if (! $expenseDraftInput['can_create']) {
                $status = 'link_skipped';
                $warning = 'Restock was saved, but expense link was skipped because required finance fields were incomplete.';
            } else {
                try {
                    $linkedExpense = DB::transaction(function () use (
                        $request,
                        $restaurant,
                        $ingredient,
                        $movement,
                        $validated,
                        $expenseDraftInput,
                        $restockQuantity,
                        $quantityBefore
                    ): Expense {
                        /** @var StockMovement $lockedMovement */
                        $lockedMovement = StockMovement::query()
                            ->whereKey($movement->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($lockedMovement->linked_expense_id) {
                            return Expense::query()->findOrFail($lockedMovement->linked_expense_id);
                        }

                        $expenseStatus = (string) ($expenseDraftInput['status'] ?? Expense::STATUS_DRAFT);
                        $paidAt = null;
                        if ($expenseStatus === Expense::STATUS_PAID) {
                            $paidAt = isset($expenseDraftInput['paid_at']) && $expenseDraftInput['paid_at'] instanceof Carbon
                                ? $expenseDraftInput['paid_at']
                                : now();
                        }

                        $expense = Expense::query()->create([
                            'uuid' => (string) Str::uuid(),
                            'restaurant_id' => $restaurant->id,
                            'expense_category_id' => (int) $expenseDraftInput['category_id'],
                            'vendor_id' => $expenseDraftInput['vendor_id'],
                            'expense_date' => now()->toDateString(),
                            'amount_cents' => (int) $expenseDraftInput['amount_cents'],
                            'tax_amount_cents' => (int) $expenseDraftInput['tax_amount_cents'],
                            'currency' => (string) $expenseDraftInput['currency'],
                            'status' => $expenseStatus,
                            'payment_method' => $expenseDraftInput['payment_method'],
                            'reference_no' => isset($validated['reference']) && is_string($validated['reference']) && trim($validated['reference']) !== ''
                                ? trim($validated['reference'])
                                : null,
                            'description' => sprintf('Restock for ingredient %s', $ingredient->name),
                            'notes' => isset($validated['notes']) && is_string($validated['notes']) && trim($validated['notes']) !== ''
                                ? trim($validated['notes'])
                                : null,
                            'due_date' => $expenseDraftInput['due_date'],
                            'paid_at' => $paidAt,
                            'created_by' => $request->user()?->id,
                            'approved_by' => $expenseStatus === Expense::STATUS_APPROVED ? $request->user()?->id : null,
                        ]);

                        $lockedMovement->update([
                            'linked_expense_id' => $expense->id,
                        ]);

                        if (
                            $restockQuantity > 0
                            && ((int) $expenseDraftInput['amount_cents'] + (int) $expenseDraftInput['tax_amount_cents']) > 0
                        ) {
                            $movementUnitCostCents = (int) round(
                                (((int) $expenseDraftInput['amount_cents'] + (int) $expenseDraftInput['tax_amount_cents']) / $restockQuantity)
                            );

                            $this->updateIngredientCostProfile(
                                ingredient: $ingredient,
                                quantityBefore: $quantityBefore,
                                restockQuantity: $restockQuantity,
                                unitCostCents: $movementUnitCostCents,
                                currency: (string) $expenseDraftInput['currency']
                            );
                        }

                        return $expense->fresh();
                    });

                    $status = 'linked';
                } catch (\Throwable $exception) {
                    report($exception);
                    $status = 'link_failed';
                    $warning = 'Restock was saved, but expense link failed. Please add expense manually from Finance > Expenses.';
                }
            }
        }

        return response()->json([
            'message' => 'Ingredient restocked successfully.',
            'ingredient' => $this->formatIngredient($ingredient->fresh()),
            'restock_finance' => [
                'status' => $status,
                'warning' => $warning,
                'stock_movement_id' => $movement->id,
                'expense_id' => $linkedExpense?->id,
            ],
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

    public function importGlobal(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'global_ingredient_ids' => ['required', 'array', 'min:1'],
            'global_ingredient_ids.*' => ['required', 'integer', 'distinct', 'exists:global_ingredients,id'],
        ]);

        $result = $this->globalIngredientProvisioningService->provisionForRestaurant(
            $restaurant,
            array_values(array_map('intval', $validated['global_ingredient_ids']))
        );

        return response()->json([
            'message' => 'Global ingredients import completed.',
            'created_count' => $result['created_count'],
            'linked_count' => $result['linked_count'],
            'skipped_count' => $result['skipped_count'],
            'created_ids' => $result['created_ids'],
            'linked_ids' => $result['linked_ids'],
            'skipped_global_ingredient_ids' => $result['skipped_global_ingredient_ids'],
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user?->loadMissing('restaurant', 'staffRestaurants');
        $restaurant = $user?->currentRestaurant();

        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account.');
        }

        return $restaurant;
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
        ?string $notes = null,
        ?int $linkedExpenseId = null,
        ?int $unitCostCents = null,
        ?int $totalCostCents = null
    ): StockMovement {
        return StockMovement::query()->create([
            'restaurant_id' => $ingredient->restaurant_id,
            'ingredient_id' => $ingredient->id,
            'performed_by' => $performedByUserId,
            'linked_expense_id' => $linkedExpenseId,
            'movement_type' => $movementType,
            'unit' => $ingredient->stock_unit,
            'quantity_delta' => round($quantityDelta, 3),
            'quantity_before' => round($quantityBefore, 3),
            'quantity_after' => round($quantityAfter, 3),
            'unit_cost_cents' => $unitCostCents,
            'total_cost_cents' => $totalCostCents,
            'ingredient_name_snapshot' => $ingredient->name,
            'reference' => $reference,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param array<string,mixed> $validated
     * @return array{
     *   wants_link:bool,
     *   can_create:bool,
     *   category_id:int|null,
     *   vendor_id:int|null,
     *   amount_cents:int,
     *   tax_amount_cents:int,
     *   currency:string|null,
     *   status:string|null,
     *   payment_method:string|null,
     *   due_date:string|null,
     *   paid_at:Carbon|null
     * }
     */
    private function prepareExpenseLinkInput(array $validated): array
    {
        $wantsLink = (bool) ($validated['create_expense'] ?? false);
        $amountCents = (int) ($validated['expense_amount_cents'] ?? 0);
        $taxAmountCents = (int) ($validated['expense_tax_amount_cents'] ?? 0);
        $currency = isset($validated['expense_currency']) && is_string($validated['expense_currency'])
            ? strtoupper(trim($validated['expense_currency']))
            : null;
        $categoryId = isset($validated['expense_category_id']) ? (int) $validated['expense_category_id'] : null;
        $vendorId = array_key_exists('expense_vendor_id', $validated) && $validated['expense_vendor_id'] !== null
            ? (int) $validated['expense_vendor_id']
            : null;
        $status = isset($validated['expense_status']) && is_string($validated['expense_status'])
            ? $validated['expense_status']
            : null;
        $paymentMethod = isset($validated['expense_payment_method']) && is_string($validated['expense_payment_method'])
            ? $validated['expense_payment_method']
            : null;
        $dueDate = isset($validated['expense_due_date']) && is_string($validated['expense_due_date']) && trim($validated['expense_due_date']) !== ''
            ? $validated['expense_due_date']
            : null;
        $paidAt = isset($validated['expense_paid_at']) && is_string($validated['expense_paid_at']) && trim($validated['expense_paid_at']) !== ''
            ? Carbon::parse($validated['expense_paid_at'])
            : null;

        $canCreate = $wantsLink
            && $categoryId !== null
            && $currency !== null
            && $currency !== ''
            && $amountCents > 0;

        return [
            'wants_link' => $wantsLink,
            'can_create' => $canCreate,
            'category_id' => $categoryId,
            'vendor_id' => $vendorId,
            'amount_cents' => $amountCents,
            'tax_amount_cents' => $taxAmountCents,
            'currency' => $currency,
            'status' => $status,
            'payment_method' => $paymentMethod,
            'due_date' => $dueDate,
            'paid_at' => $paidAt,
        ];
    }

    private function updateIngredientCostProfile(
        Ingredient $ingredient,
        float $quantityBefore,
        float $restockQuantity,
        int $unitCostCents,
        string $currency
    ): void {
        if ($restockQuantity <= 0) {
            return;
        }

        $previousAverage = $ingredient->average_cost_cents
            ?? $ingredient->unit_cost_cents
            ?? $ingredient->last_cost_cents
            ?? $unitCostCents;

        $combinedQuantity = $quantityBefore + $restockQuantity;
        $nextAverage = $combinedQuantity > 0
            ? (int) round((($previousAverage * $quantityBefore) + ($unitCostCents * $restockQuantity)) / $combinedQuantity)
            : $unitCostCents;

        $ingredient->update([
            'unit_cost_cents' => $unitCostCents,
            'last_cost_cents' => $unitCostCents,
            'average_cost_cents' => $nextAverage,
            'cost_currency' => strtoupper($currency),
        ]);
    }

    private function formatIngredient(Ingredient $ingredient): array
    {
        $currentQuantity = (float) $ingredient->current_stock_quantity;
        $lowStockThreshold = (float) $ingredient->low_stock_threshold;
        $targetQuantity = (float) ($ingredient->target_quantity ?? $ingredient->low_stock_threshold);

        return [
            'id' => $ingredient->id,
            'uuid' => $ingredient->uuid,
            'name' => $ingredient->name,
            'name_ar' => $ingredient->name_ar,
            'global_ingredient_id' => $ingredient->global_ingredient_id,
            'file_url' => $ingredient->file_url,
            'image_url' => $ingredient->file_url,
            'unit' => $ingredient->stock_unit,
            'current_quantity' => $this->formatQuantity($currentQuantity),
            'low_stock_threshold' => $this->formatQuantity($lowStockThreshold),
            'target_quantity' => $this->formatQuantity($targetQuantity),
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

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
