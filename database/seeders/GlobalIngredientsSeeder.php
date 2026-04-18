<?php

namespace Database\Seeders;

use App\Models\GlobalIngredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GlobalIngredientsSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = base_path('../Menu_React/src/i18n/ingredients.ts');

        if (! file_exists($filePath)) {
            $this->command?->warn('GlobalIngredientsSeeder skipped: ingredients.ts not found.');

            return;
        }

        $content = (string) file_get_contents($filePath);

        if ($content === '') {
            $this->command?->warn('GlobalIngredientsSeeder skipped: ingredients.ts is empty.');

            return;
        }

        if (! preg_match('/export const ingredientTranslations: Record<string, string> = \{(.*?)\n\};/su', $content, $matches)) {
            $this->command?->warn('GlobalIngredientsSeeder skipped: ingredientTranslations map was not found.');

            return;
        }

        $rawMap = $matches[1] ?? '';
        preg_match_all("/^\s*'((?:\\'|[^'])+)'\s*:\s*'((?:\\'|[^'])*)'\s*,?\s*$/mu", $rawMap, $pairs, PREG_SET_ORDER);

        $seededCount = 0;

        foreach ($pairs as $pair) {
            $name = trim(stripcslashes((string) ($pair[1] ?? '')));
            $nameAr = trim(stripcslashes((string) ($pair[2] ?? '')));

            if ($name === '') {
                continue;
            }

            $normalizedName = $this->normalizeIngredientName($name);
            if ($normalizedName === '') {
                continue;
            }

            $globalIngredient = GlobalIngredient::query()->firstOrNew([
                'normalized_name' => $normalizedName,
            ]);

            if (! $globalIngredient->exists) {
                $globalIngredient->uuid = (string) Str::uuid();
            }

            $globalIngredient->name = $name;
            $globalIngredient->name_ar = $nameAr !== '' ? $nameAr : null;
            $globalIngredient->save();

            $seededCount++;
        }

        $this->command?->info("GlobalIngredientsSeeder completed. Upserted {$seededCount} ingredients.");
    }

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
