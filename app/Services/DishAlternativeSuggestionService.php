<?php

namespace App\Services;

use App\Models\Dish;
use Illuminate\Support\Collection;

class DishAlternativeSuggestionService
{
    public function suggestForDish(Dish $dish, int $limit = 4): Collection
    {
        $limit = max(1, min($limit, 5));

        $baseQuery = Dish::query()
            ->where('restaurant_id', $dish->restaurant_id)
            ->where('status', 'published')
            ->whereKeyNot($dish->id)
            ->with(['assets', 'dishIngredients.ingredient'])
            ->orderBy('name');

        $sameCategoryQuery = (clone $baseQuery);
        if ($dish->category === null) {
            $sameCategoryQuery->whereNull('category');
        } else {
            $sameCategoryQuery->where('category', $dish->category);
        }

        $sameCategory = $sameCategoryQuery->get();

        $sameCategoryOrderable = $this->rankSimilar($sameCategory, $dish)
            ->filter(fn (Dish $candidate) => $candidate->isOrderable())
            ->values();

        if ($sameCategoryOrderable->count() >= $limit) {
            return $sameCategoryOrderable->take($limit)->values();
        }

        $remaining = $limit - $sameCategoryOrderable->count();

        $otherCategoriesQuery = (clone $baseQuery);
        if ($dish->category === null) {
            $otherCategoriesQuery->whereNotNull('category');
        } else {
            $otherCategoriesQuery->where(function ($query) use ($dish) {
                $query->where('category', '!=', $dish->category)
                    ->orWhereNull('category');
            });
        }

        $otherCategories = $otherCategoriesQuery->get();

        $otherOrderable = $this->rankSimilar($otherCategories, $dish)
            ->filter(fn (Dish $candidate) => $candidate->isOrderable())
            ->take($remaining)
            ->values();

        return $sameCategoryOrderable
            ->concat($otherOrderable)
            ->take($limit)
            ->values();
    }

    private function rankSimilar(Collection $candidates, Dish $targetDish): Collection
    {
        $targetPrice = (float) $targetDish->price;

        return $candidates->sortBy([
            fn (Dish $candidate) => abs(((float) $candidate->price) - $targetPrice),
            fn (Dish $candidate) => mb_strtolower($candidate->name),
        ])->values();
    }
}
