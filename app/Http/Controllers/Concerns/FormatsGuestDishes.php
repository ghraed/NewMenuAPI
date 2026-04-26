<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Dish;

trait FormatsGuestDishes
{
    private function localizeDishes($dishes, bool $ar3dEnabled = true, bool $animatedIngredientsEnabled = true): array
    {
        return $dishes
            ->map(fn (Dish $dish) => $this->localizeDish($dish, $ar3dEnabled, $animatedIngredientsEnabled))
            ->values()
            ->all();
    }

    private function localizeDish(
        Dish $dish,
        bool $ar3dEnabled = true,
        bool $animatedIngredientsEnabled = true
    ): array
    {
        $localized = $dish->toLocalizedArray();

        if ($dish->relationLoaded('suggestedDishes')) {
            $localized['suggested_dishes'] = $this->localizeDishes(
                $dish->suggestedDishes,
                $ar3dEnabled,
                $animatedIngredientsEnabled
            );
        }

        if ($dish->relationLoaded('relatedDishes')) {
            $localized['related_dishes'] = $this->localizeDishes(
                $dish->relatedDishes,
                $ar3dEnabled,
                $animatedIngredientsEnabled
            );
        }

        if ($dish->relationLoaded('alternativeDishes')) {
            $localized['alternative_dishes'] = $this->localizeDishes(
                $dish->alternativeDishes,
                $ar3dEnabled,
                $animatedIngredientsEnabled
            );
        }

        return $this->applyFeatureVisibilityToLocalizedDish(
            $localized,
            $ar3dEnabled,
            $animatedIngredientsEnabled
        );
    }

    private function applyFeatureVisibilityToLocalizedDish(
        array $localizedDish,
        bool $ar3dEnabled,
        bool $animatedIngredientsEnabled
    ): array {
        if (! $ar3dEnabled && isset($localizedDish['assets']) && is_array($localizedDish['assets'])) {
            $localizedDish['assets'] = array_values(array_filter(
                $localizedDish['assets'],
                fn ($asset): bool => ! is_array($asset) || ($asset['asset_type'] ?? null) !== 'glb'
            ));
            $localizedDish['is_model_ready'] = false;
            $localizedDish['model_state'] = 'none';
        }

        if (
            ! $animatedIngredientsEnabled
            && isset($localizedDish['dish_ingredients'])
            && is_array($localizedDish['dish_ingredients'])
        ) {
            $localizedDish['dish_ingredients'] = array_map(function ($row): array {
                if (! is_array($row)) {
                    return ['show_in_animation' => false];
                }

                $row['show_in_animation'] = false;

                return $row;
            }, $localizedDish['dish_ingredients']);
        }

        return $localizedDish;
    }
}
