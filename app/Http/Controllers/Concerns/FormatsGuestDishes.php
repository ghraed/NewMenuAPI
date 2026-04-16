<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Dish;

trait FormatsGuestDishes
{
    private function localizeDishes($dishes): array
    {
        return $dishes
            ->map(fn (Dish $dish) => $this->localizeDish($dish))
            ->values()
            ->all();
    }

    private function localizeDish(Dish $dish): array
    {
        $localized = $dish->toLocalizedArray();

        if ($dish->relationLoaded('suggestedDishes')) {
            $localized['suggested_dishes'] = $this->localizeDishes($dish->suggestedDishes);
        }

        if ($dish->relationLoaded('relatedDishes')) {
            $localized['related_dishes'] = $this->localizeDishes($dish->relatedDishes);
        }

        return $localized;
    }
}
