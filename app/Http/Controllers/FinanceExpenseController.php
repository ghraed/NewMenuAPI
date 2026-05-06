<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Restaurant;
use App\Models\StockMovement;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FinanceExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in([Expense::STATUS_DRAFT, Expense::STATUS_APPROVED, Expense::STATUS_PAID, Expense::STATUS_VOID])],
            'category_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Expense::query()
            ->where('restaurant_id', $restaurant->id)
            ->with(['category', 'vendor', 'linkedStockMovement.ingredient'])
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if (! empty($validated['date_from'])) {
            $query->whereDate('expense_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('expense_date', '<=', $validated['date_to']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['category_id'])) {
            $query->where('expense_category_id', (int) $validated['category_id']);
        }
        if (! empty($validated['vendor_id'])) {
            $query->where('vendor_id', (int) $validated['vendor_id']);
        }

        $perPage = (int) ($validated['per_page'] ?? 80);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'expenses' => collect($paginator->items())->map(fn (Expense $expense): array => $this->formatExpense($expense))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $validated = $this->validatePayload($request, $restaurant, false);

        $expense = Expense::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'expense_category_id' => (int) $validated['expense_category_id'],
            'vendor_id' => isset($validated['vendor_id']) ? (int) $validated['vendor_id'] : null,
            'expense_date' => $validated['expense_date'],
            'amount_cents' => (int) $validated['amount_cents'],
            'tax_amount_cents' => (int) ($validated['tax_amount_cents'] ?? 0),
            'currency' => strtoupper((string) $validated['currency']),
            'status' => $validated['status'] ?? Expense::STATUS_DRAFT,
            'payment_method' => $validated['payment_method'] ?? null,
            'reference_no' => $this->normalizeOptionalString($validated['reference_no'] ?? null),
            'description' => $this->normalizeOptionalString($validated['description'] ?? null),
            'notes' => $this->normalizeOptionalString($validated['notes'] ?? null),
            'due_date' => $validated['due_date'] ?? null,
            'paid_at' => $this->resolvePaidAtForWrite(
                status: $validated['status'] ?? Expense::STATUS_DRAFT,
                paidAtInput: $validated['paid_at'] ?? null
            ),
            'created_by' => $request->user()?->id,
            'approved_by' => ($validated['status'] ?? Expense::STATUS_DRAFT) === Expense::STATUS_APPROVED
                ? $request->user()?->id
                : null,
        ]);

        $expense->load(['category', 'vendor', 'linkedStockMovement.ingredient']);

        return response()->json([
            'message' => 'Expense created successfully.',
            'expense' => $this->formatExpense($expense),
        ], 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $expense = $this->assertBelongsToRestaurant($expense, $restaurant);
        $validated = $this->validatePayload($request, $restaurant, true, $expense);

        $payload = [];

        if (array_key_exists('expense_category_id', $validated)) {
            $payload['expense_category_id'] = (int) $validated['expense_category_id'];
        }
        if (array_key_exists('vendor_id', $validated)) {
            $payload['vendor_id'] = $validated['vendor_id'] !== null ? (int) $validated['vendor_id'] : null;
        }
        if (array_key_exists('expense_date', $validated)) {
            $payload['expense_date'] = $validated['expense_date'];
        }
        if (array_key_exists('amount_cents', $validated)) {
            $payload['amount_cents'] = (int) $validated['amount_cents'];
        }
        if (array_key_exists('tax_amount_cents', $validated)) {
            $payload['tax_amount_cents'] = (int) $validated['tax_amount_cents'];
        }
        if (array_key_exists('currency', $validated)) {
            $payload['currency'] = strtoupper((string) $validated['currency']);
        }
        if (array_key_exists('payment_method', $validated)) {
            $payload['payment_method'] = $validated['payment_method'];
        }
        if (array_key_exists('reference_no', $validated)) {
            $payload['reference_no'] = $this->normalizeOptionalString($validated['reference_no']);
        }
        if (array_key_exists('description', $validated)) {
            $payload['description'] = $this->normalizeOptionalString($validated['description']);
        }
        if (array_key_exists('notes', $validated)) {
            $payload['notes'] = $this->normalizeOptionalString($validated['notes']);
        }
        if (array_key_exists('due_date', $validated)) {
            $payload['due_date'] = $validated['due_date'];
        }

        $nextStatus = $validated['status'] ?? $expense->status;
        if (array_key_exists('status', $validated)) {
            $payload['status'] = $nextStatus;
        }
        if ($nextStatus === Expense::STATUS_APPROVED && $expense->status !== Expense::STATUS_APPROVED) {
            $payload['approved_by'] = $request->user()?->id;
        }
        if ($nextStatus !== Expense::STATUS_APPROVED && array_key_exists('status', $validated)) {
            $payload['approved_by'] = null;
        }

        if (array_key_exists('paid_at', $validated) || array_key_exists('status', $validated)) {
            $payload['paid_at'] = $this->resolvePaidAtForWrite(
                status: $nextStatus,
                paidAtInput: $validated['paid_at'] ?? null,
                fallbackPaidAt: $expense->paid_at
            );
        }

        if ($payload !== []) {
            $expense->update($payload);
        }

        $expense->load(['category', 'vendor', 'linkedStockMovement.ingredient']);

        return response()->json([
            'message' => 'Expense updated successfully.',
            'expense' => $this->formatExpense($expense->fresh(['category', 'vendor', 'linkedStockMovement.ingredient'])),
        ]);
    }

    public function unlinkedRestocks(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'ingredient_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = StockMovement::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('movement_type', StockMovement::TYPE_RESTOCK)
            ->whereNull('linked_expense_id')
            ->with('ingredient:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }
        if (! empty($validated['ingredient_id'])) {
            $query->where('ingredient_id', (int) $validated['ingredient_id']);
        }

        $perPage = (int) ($validated['per_page'] ?? 80);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'restocks' => collect($paginator->items())->map(function (StockMovement $movement): array {
                $createdDate = $movement->created_at?->copy()->startOfDay();
                $today = now()->startOfDay();
                $ageDays = $createdDate ? (int) $createdDate->diffInDays($today) : 0;

                return [
                    'id' => $movement->id,
                    'ingredient_id' => $movement->ingredient_id,
                    'ingredient_name' => $movement->ingredient?->name ?: $movement->ingredient_name_snapshot,
                    'quantity_delta' => number_format((float) $movement->quantity_delta, 3, '.', ''),
                    'unit' => $movement->unit,
                    'reference' => $movement->reference,
                    'notes' => $movement->notes,
                    'created_at' => $movement->created_at?->toISOString(),
                    'age_days' => $ageDays,
                    'is_flagged' => $ageDays >= 1,
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function validatePayload(
        Request $request,
        Restaurant $restaurant,
        bool $isUpdate,
        ?Expense $existing = null
    ): array {
        $required = $isUpdate ? 'sometimes|required' : 'required';
        $nullable = $isUpdate ? 'sometimes|nullable' : 'nullable';

        return $request->validate([
            'expense_category_id' => [
                $required,
                'integer',
                Rule::exists('expense_categories', 'id')->where('restaurant_id', $restaurant->id),
            ],
            'vendor_id' => [
                $nullable,
                'integer',
                Rule::exists('vendors', 'id')->where('restaurant_id', $restaurant->id),
            ],
            'expense_date' => [$required, 'date'],
            'amount_cents' => [$required, 'integer', 'min:0'],
            'tax_amount_cents' => ['sometimes', 'integer', 'min:0'],
            'currency' => [$required, 'string', 'size:3'],
            'status' => ['sometimes', Rule::in([Expense::STATUS_DRAFT, Expense::STATUS_APPROVED, Expense::STATUS_PAID, Expense::STATUS_VOID])],
            'payment_method' => ['sometimes', 'nullable', Rule::in(['cash', 'card', 'bank_transfer', 'wallet', 'other'])],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    private function resolvePaidAtForWrite(string $status, mixed $paidAtInput, ?Carbon $fallbackPaidAt = null): ?Carbon
    {
        if ($status !== Expense::STATUS_PAID) {
            return null;
        }

        if (is_string($paidAtInput) && trim($paidAtInput) !== '') {
            return Carbon::parse($paidAtInput);
        }

        return $fallbackPaidAt ?? now();
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');

        $restaurant = $user->currentRestaurant();
        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $restaurant;
    }

    private function assertBelongsToRestaurant(Expense $expense, Restaurant $restaurant): Expense
    {
        if ((int) $expense->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }

        return $expense;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatExpense(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'uuid' => $expense->uuid,
            'restaurant_id' => $expense->restaurant_id,
            'expense_category_id' => $expense->expense_category_id,
            'vendor_id' => $expense->vendor_id,
            'expense_date' => $expense->expense_date?->toDateString(),
            'amount_cents' => (int) $expense->amount_cents,
            'tax_amount_cents' => (int) $expense->tax_amount_cents,
            'total_cents' => (int) $expense->total_cents,
            'currency' => $expense->currency,
            'status' => $expense->status,
            'payment_method' => $expense->payment_method,
            'reference_no' => $expense->reference_no,
            'description' => $expense->description,
            'notes' => $expense->notes,
            'due_date' => $expense->due_date?->toDateString(),
            'paid_at' => $expense->paid_at?->toISOString(),
            'created_by' => $expense->created_by,
            'approved_by' => $expense->approved_by,
            'created_at' => $expense->created_at?->toISOString(),
            'updated_at' => $expense->updated_at?->toISOString(),
            'category' => $expense->category ? [
                'id' => $expense->category->id,
                'code' => $expense->category->code,
                'name' => $expense->category->name,
            ] : null,
            'vendor' => $expense->vendor ? [
                'id' => $expense->vendor->id,
                'name' => $expense->vendor->name,
            ] : null,
            'linked_stock_movement' => $expense->linkedStockMovement ? [
                'id' => $expense->linkedStockMovement->id,
                'ingredient_id' => $expense->linkedStockMovement->ingredient_id,
                'ingredient_name' => $expense->linkedStockMovement->ingredient?->name
                    ?: $expense->linkedStockMovement->ingredient_name_snapshot,
                'quantity_delta' => number_format((float) $expense->linkedStockMovement->quantity_delta, 3, '.', ''),
                'unit' => $expense->linkedStockMovement->unit,
                'created_at' => $expense->linkedStockMovement->created_at?->toISOString(),
            ] : null,
        ];
    }
}
