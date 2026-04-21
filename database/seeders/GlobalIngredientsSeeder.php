<?php

namespace Database\Seeders;

use App\Models\GlobalIngredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
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

        $candidates = [];

        foreach ($pairs as $pair) {
            $name = trim(stripcslashes((string) ($pair[1] ?? '')));
            $nameAr = trim(stripcslashes((string) ($pair[2] ?? '')));

            if ($name === '') {
                continue;
            }

            $canonicalName = $this->canonicalIngredientName($name);
            $normalizedName = $this->normalizeIngredientName($canonicalName);
            if ($normalizedName === '') {
                continue;
            }

            if (! isset($candidates[$normalizedName])) {
                $candidates[$normalizedName] = [
                    'name' => $canonicalName,
                    'name_ar' => $nameAr !== '' ? $nameAr : null,
                    'source_name' => $name,
                ];
                continue;
            }

            $existing = $candidates[$normalizedName];
            $existingName = (string) Arr::get($existing, 'name', '');

            $preferCurrent = $this->isBetterCanonicalName($canonicalName, $existingName);
            $chosenName = $preferCurrent ? $canonicalName : $existingName;
            $chosenArabic = Arr::get($existing, 'name_ar');
            if (! $chosenArabic && $nameAr !== '') {
                $chosenArabic = $nameAr;
            }

            $candidates[$normalizedName] = [
                'name' => $chosenName,
                'name_ar' => $chosenArabic,
                'source_name' => $preferCurrent ? $name : Arr::get($existing, 'source_name'),
            ];
        }

        $seededCount = 0;

        foreach ($candidates as $normalizedName => $candidate) {
            $globalIngredient = GlobalIngredient::query()->firstOrNew([
                'normalized_name' => $normalizedName,
            ]);

            if (! $globalIngredient->exists) {
                $globalIngredient->uuid = (string) Str::uuid();
            }

            $globalIngredient->name = (string) $candidate['name'];
            $globalIngredient->name_ar = $candidate['name_ar'];
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

    private function canonicalIngredientName(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        $singularized = Str::of($trimmed)
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->explode(' ')
            ->map(fn (string $token) => Str::singular($token))
            ->implode(' ');

        return trim((string) $singularized);
    }

    private function isBetterCanonicalName(string $candidate, string $current): bool
    {
        if ($current === '') {
            return true;
        }

        $candidateTokens = preg_split('/\s+/', trim($candidate)) ?: [];
        $currentTokens = preg_split('/\s+/', trim($current)) ?: [];

        if (count($candidateTokens) !== count($currentTokens)) {
            return count($candidateTokens) < count($currentTokens);
        }

        return strlen($candidate) < strlen($current);
    }
}
