<?php

namespace Database\Seeders;

use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GlobalIngredientArabicRepairSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = $this->resolveIngredientsPath();

        if (! $filePath || ! file_exists($filePath)) {
            $this->command?->warn('GlobalIngredientArabicRepairSeeder skipped: ingredients.ts not found.');

            return;
        }

        $translations = $this->loadTranslationsFromFile($filePath);

        if ($translations === []) {
            $this->command?->warn('GlobalIngredientArabicRepairSeeder skipped: no translations found.');

            return;
        }

        $globalUpdated = 0;
        $ingredientUpdated = 0;

        GlobalIngredient::query()
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($translations, &$globalUpdated): void {
                foreach ($rows as $row) {
                    $normalizedName = (string) ($row->normalized_name ?: $this->normalizeIngredientName((string) $row->name));
                    if ($normalizedName === '') {
                        continue;
                    }

                    $correctArabic = $translations[$normalizedName] ?? null;
                    if (! is_string($correctArabic) || trim($correctArabic) === '') {
                        continue;
                    }

                    $currentArabic = (string) ($row->name_ar ?? '');
                    if (! $this->shouldRepairArabic($currentArabic, $correctArabic)) {
                        continue;
                    }

                    $row->update([
                        'name_ar' => $correctArabic,
                    ]);

                    $globalUpdated++;
                }
            });

        Ingredient::query()
            ->with('globalIngredient:id,name_ar')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($translations, &$ingredientUpdated): void {
                foreach ($rows as $row) {
                    $normalizedName = $this->normalizeIngredientName((string) $row->name);
                    if ($normalizedName === '') {
                        continue;
                    }

                    $correctArabic = $translations[$normalizedName]
                        ?? $row->globalIngredient?->name_ar
                        ?? null;

                    if (! is_string($correctArabic) || trim($correctArabic) === '') {
                        continue;
                    }

                    $currentArabic = (string) ($row->name_ar ?? '');
                    if (! $this->shouldRepairArabic($currentArabic, $correctArabic)) {
                        continue;
                    }

                    $row->update([
                        'name_ar' => $correctArabic,
                    ]);

                    $ingredientUpdated++;
                }
            });

        $this->command?->info("GlobalIngredientArabicRepairSeeder updated {$globalUpdated} global ingredient rows and {$ingredientUpdated} ingredient rows.");
    }

    private function resolveIngredientsPath(): ?string
    {
        // path
        $paths = [
            '/var/NewMenuReact/src/i18n/ingredients.ts',
            base_path('../Menu_React/src/i18n/ingredients.ts'),
            base_path('../NewMenuReact/src/i18n/ingredients.ts'),
            base_path('resources/seed/ingredients.ts'),
            base_path('storage/app/seed/ingredients.ts'),
        ];

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslationsFromFile(string $filePath): array
    {
        $content = (string) file_get_contents($filePath);

        if ($content === '') {
            return [];
        }

        if (! preg_match('/ingredientTranslations\s*:\s*Record\s*<\s*string\s*,\s*string\s*>\s*=\s*\{(.*?)\n\s*\};/su', $content, $matches)) {
            return [];
        }

        $rawMap = $matches[1] ?? '';
        preg_match_all('/^\s*[\'\"]((?:\\.|[^\'\"])*)[\'\"]\s*:\s*[\'\"]((?:\\.|[^\'\"])*)[\'\"]\s*,?\s*$/mu', $rawMap, $pairs, PREG_SET_ORDER);

        $translations = [];

        foreach ($pairs as $pair) {
            $name = trim(stripcslashes((string) ($pair[1] ?? '')));
            $nameAr = trim(stripcslashes((string) ($pair[2] ?? '')));

            if ($name === '' || $nameAr === '') {
                continue;
            }

            $canonicalName = $this->canonicalIngredientName($name);
            $translations[$this->normalizeIngredientName($canonicalName)] = $nameAr;
        }

        return $translations;
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
            ->map(fn(string $token) => Str::singular($token))
            ->implode(' ');

        return trim((string) $singularized);
    }

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function shouldRepairArabic(string $currentArabic, string $correctArabic): bool
    {
        $currentArabic = trim($currentArabic);
        $correctArabic = trim($correctArabic);

        if ($correctArabic === '') {
            return false;
        }

        if ($currentArabic === '') {
            return true;
        }

        if ($currentArabic === $correctArabic) {
            return false;
        }

        return $this->isLikelyMojibake($currentArabic);
    }

    private function isLikelyMojibake(string $value): bool
    {
        return preg_match('/Ø|Ù|Ã|Â/', $value) === 1;
    }
}
