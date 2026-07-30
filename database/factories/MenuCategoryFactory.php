<?php

namespace Database\Factories;

final class MenuCategoryFactory
{
    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [
            'Starters',
            'Mains',
            'Desserts',
            'Drinks',
            'Chef Specials',
            'Breakfast',
            'Lunch',
            'Dinner',
        ];
    }

    public static function random(): string
    {
        $categories = self::names();

        return $categories[array_rand($categories)];
    }
}
