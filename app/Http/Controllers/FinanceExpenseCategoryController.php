<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FinanceExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $categories = ExpenseCategory::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderByRaw('is_active desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => $categories->map(fn (ExpenseCategory $category): array => $this->formatCategory($category))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('expense_categories', 'code')->where('restaurant_id', $restaurant->id),
            ],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('expense_categories', 'name')->where('restaurant_id', $restaurant->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $category = ExpenseCategory::query()->create([
            'restaurant_id' => $restaurant->id,
            'code' => Str::of((string) $validated['code'])->trim()->lower()->replaceMatches('/\s+/', '_')->toString(),
            'name' => trim((string) $validated['name']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Expense category created successfully.',
            'category' => $this->formatCategory($category),
        ], 201);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $category = $this->assertBelongsToRestaurant($expenseCategory, $restaurant);

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('expense_categories', 'code')
                    ->where('restaurant_id', $restaurant->id)
                    ->ignore($category->id),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('expense_categories', 'name')
                    ->where('restaurant_id', $restaurant->id)
                    ->ignore($category->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $payload = [];
        if (array_key_exists('code', $validated)) {
            $payload['code'] = Str::of((string) $validated['code'])->trim()->lower()->replaceMatches('/\s+/', '_')->toString();
        }
        if (array_key_exists('name', $validated)) {
            $payload['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = (bool) $validated['is_active'];
        }

        if ($payload !== []) {
            $category->update($payload);
        }

        return response()->json([
            'message' => 'Expense category updated successfully.',
            'category' => $this->formatCategory($category->fresh()),
        ]);
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

    private function assertBelongsToRestaurant(ExpenseCategory $category, Restaurant $restaurant): ExpenseCategory
    {
        if ((int) $category->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }

        return $category;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatCategory(ExpenseCategory $category): array
    {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'is_active' => (bool) $category->is_active,
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
        ];
    }
}

