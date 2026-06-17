<?php

namespace Database\Seeders;

use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class GlobalIngredientImagePathBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $updated = 0;
        $linkedIngredients = 0;

        GlobalIngredient::query()
            ->orderBy('id')
            ->chunkById(200, function ($ingredients) use (&$updated): void {
                foreach ($ingredients as $ingredient) {
                    $directory = "global-ingredients/{$ingredient->id}";
                    $disk = 'public';

                    if (! Storage::disk($disk)->exists($directory)) {
                        continue;
                    }

                    $files = Storage::disk($disk)->files($directory);
                    if ($files === []) {
                        continue;
                    }

                    $preferredPath = $this->pickPreferredImagePath($files);
                    if (! $preferredPath) {
                        continue;
                    }

                    $fileName = basename($preferredPath);
                    $fileSize = Storage::disk($disk)->size($preferredPath);
                    $mimeType = Storage::disk($disk)->mimeType($preferredPath) ?: $ingredient->mime_type ?: 'image/webp';

                    $payload = [
                        'storage_disk' => $disk,
                        'file_path' => $preferredPath,
                        'source_file_name' => $fileName,
                        'file_size' => $fileSize ?: null,
                        'mime_type' => $mimeType,
                    ];

                    $hasChanges =
                        (string) ($ingredient->storage_disk ?: '') !== (string) $payload['storage_disk']
                        || (string) ($ingredient->file_path ?: '') !== (string) $payload['file_path']
                        || (string) ($ingredient->source_file_name ?: '') !== (string) $payload['source_file_name']
                        || (int) ($ingredient->file_size ?: 0) !== (int) ($payload['file_size'] ?: 0)
                        || (string) ($ingredient->mime_type ?: '') !== (string) $payload['mime_type'];

                    if (! $hasChanges) {
                        continue;
                    }

                    $ingredient->update($payload);
                    $updated++;
                }
            });

        $globalIdsByNormalizedName = GlobalIngredient::query()
            ->select(['id', 'normalized_name'])
            ->whereNotNull('normalized_name')
            ->where('normalized_name', '!=', '')
            ->pluck('id', 'normalized_name');

        Ingredient::query()
            ->orderBy('id')
            ->chunkById(200, function ($ingredients) use ($globalIdsByNormalizedName, &$linkedIngredients): void {
                foreach ($ingredients as $ingredient) {
                    $normalizedName = $this->normalizeIngredientName((string) $ingredient->name);
                    if ($normalizedName === '') {
                        continue;
                    }

                    $globalId = $globalIdsByNormalizedName[$normalizedName] ?? null;
                    if (! $globalId) {
                        continue;
                    }

                    if ((int) $ingredient->global_ingredient_id === (int) $globalId) {
                        continue;
                    }

                    $ingredient->update([
                        'global_ingredient_id' => (int) $globalId,
                    ]);

                    $linkedIngredients++;
                }
            });

        $this->command?->info("GlobalIngredientImagePathBackfillSeeder updated {$updated} global ingredient rows and linked {$linkedIngredients} ingredient rows.");
    }

    /**
     * @param array<int, string> $files
     */
    private function pickPreferredImagePath(array $files): ?string
    {
        if ($files === []) {
            return null;
        }

        usort($files, function (string $left, string $right): int {
            return $this->imagePriority($left) <=> $this->imagePriority($right);
        });

        return $files[0] ?? null;
    }

    private function imagePriority(string $path): int
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'webp' => 0,
            'png' => 1,
            'jpg', 'jpeg' => 2,
            default => 9,
        };
    }

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
