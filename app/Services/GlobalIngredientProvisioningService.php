<?php

namespace App\Services;

use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GlobalIngredientProvisioningService
{
    /**
     * @param  array<int>|null  $globalIngredientIds
     * @return array{
     *   created_count:int,
     *   linked_count:int,
     *   skipped_count:int,
     *   created_ids:array<int,int>,
     *   linked_ids:array<int,int>,
     *   skipped_global_ingredient_ids:array<int,int>
     * }
     */
    public function provisionForRestaurant(Restaurant $restaurant, ?array $globalIngredientIds = null): array
    {
        return DB::transaction(function () use ($restaurant, $globalIngredientIds): array {
            $requestedIds = $globalIngredientIds === null
                ? GlobalIngredient::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all()
                : array_values(array_unique(array_map('intval', $globalIngredientIds)));

            $globalIngredients = GlobalIngredient::query()
                ->whereIn('id', $requestedIds)
                ->get()
                ->keyBy('id');

            $createdIds = [];
            $linkedIds = [];
            $skippedGlobalIngredientIds = [];

            $existingIngredients = Ingredient::query()
                ->where('restaurant_id', $restaurant->id)
                ->lockForUpdate()
                ->get();

            $existingByGlobalId = [];
            $unlinkedByNormalizedName = [];
            $existingNormalizedNames = [];

            foreach ($existingIngredients as $existingIngredient) {
                $normalizedName = $this->normalizeIngredientName((string) $existingIngredient->name);
                if ($normalizedName !== '') {
                    $existingNormalizedNames[$normalizedName] = true;
                }

                if ($existingIngredient->global_ingredient_id) {
                    $existingByGlobalId[(int) $existingIngredient->global_ingredient_id] = $existingIngredient;
                    continue;
                }

                if ($normalizedName !== '' && ! isset($unlinkedByNormalizedName[$normalizedName])) {
                    $unlinkedByNormalizedName[$normalizedName] = $existingIngredient;
                }
            }

            foreach ($requestedIds as $globalIngredientId) {
                $globalIngredient = $globalIngredients->get($globalIngredientId);

                if (! $globalIngredient) {
                    $skippedGlobalIngredientIds[] = (int) $globalIngredientId;
                    continue;
                }

                $numericGlobalIngredientId = (int) $globalIngredient->id;

                if (isset($existingByGlobalId[$numericGlobalIngredientId])) {
                    $skippedGlobalIngredientIds[] = $numericGlobalIngredientId;
                    continue;
                }

                $normalizedName = $globalIngredient->normalized_name
                    ?: $this->normalizeIngredientName((string) $globalIngredient->name);

                if ($normalizedName !== '' && isset($unlinkedByNormalizedName[$normalizedName])) {
                    $ingredientToLink = $unlinkedByNormalizedName[$normalizedName];
                    $ingredientToLink->update([
                        'global_ingredient_id' => $numericGlobalIngredientId,
                        'name_ar' => $ingredientToLink->name_ar ?: $globalIngredient->name_ar,
                    ]);

                    $linkedIds[] = (int) $ingredientToLink->id;
                    $existingByGlobalId[$numericGlobalIngredientId] = $ingredientToLink;
                    unset($unlinkedByNormalizedName[$normalizedName]);
                    continue;
                }

                if ($normalizedName !== '' && isset($existingNormalizedNames[$normalizedName])) {
                    $skippedGlobalIngredientIds[] = $numericGlobalIngredientId;
                    continue;
                }

                $createdIngredient = Ingredient::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'restaurant_id' => $restaurant->id,
                    'global_ingredient_id' => $numericGlobalIngredientId,
                    'name' => trim((string) $globalIngredient->name),
                    'name_ar' => $globalIngredient->name_ar,
                    'stock_unit' => Ingredient::UNIT_PIECE,
                    'current_stock_quantity' => 0,
                    'low_stock_threshold' => 0,
                    'target_quantity' => 0,
                    'is_active' => true,
                    'storage_disk' => 'public',
                    'file_path' => null,
                    'source_file_name' => null,
                    'file_size' => null,
                    'mime_type' => null,
                ]);

                $createdIds[] = (int) $createdIngredient->id;
                $existingByGlobalId[$numericGlobalIngredientId] = $createdIngredient;
                if ($normalizedName !== '') {
                    $existingNormalizedNames[$normalizedName] = true;
                }
            }

            return [
                'created_count' => count($createdIds),
                'linked_count' => count($linkedIds),
                'skipped_count' => count($skippedGlobalIngredientIds),
                'created_ids' => $createdIds,
                'linked_ids' => $linkedIds,
                'skipped_global_ingredient_ids' => $skippedGlobalIngredientIds,
            ];
        });
    }

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
