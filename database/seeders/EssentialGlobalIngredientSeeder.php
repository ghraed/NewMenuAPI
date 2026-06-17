<?php

namespace Database\Seeders;

use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class EssentialGlobalIngredientSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Salt', 'name_ar' => 'ملح', 'normalized_name' => 'salt'],
            ['name' => 'Egg', 'name_ar' => 'بيضة', 'normalized_name' => 'egg'],
            ['name' => 'Yogurt', 'name_ar' => 'زبادي', 'normalized_name' => 'yogurt'],
            ['name' => 'Yeast', 'name_ar' => 'خميرة', 'normalized_name' => 'yeast'],
            ['name' => 'Cornstarch', 'name_ar' => 'نشا الذرة', 'normalized_name' => 'cornstarch'],
            ['name' => 'Semolina', 'name_ar' => 'سميد', 'normalized_name' => 'semolina'],
            ['name' => 'Cumin', 'name_ar' => 'كمون', 'normalized_name' => 'cumin'],
            ['name' => 'Paprika', 'name_ar' => 'بابريكا', 'normalized_name' => 'paprika'],
            ['name' => 'Oregano', 'name_ar' => 'أوريغانو', 'normalized_name' => 'oregano'],
            ['name' => 'Thyme', 'name_ar' => 'زعتر', 'normalized_name' => 'thyme'],
            ['name' => 'Rosemary', 'name_ar' => 'إكليل الجبل', 'normalized_name' => 'rosemary'],
            ['name' => 'Turmeric', 'name_ar' => 'كركم', 'normalized_name' => 'turmeric'],
            ['name' => 'Cinnamon', 'name_ar' => 'قرفة', 'normalized_name' => 'cinnamon'],
            ['name' => 'Celery', 'name_ar' => 'كرفس', 'normalized_name' => 'celery'],
            ['name' => 'Zucchini', 'name_ar' => 'كوسا', 'normalized_name' => 'zucchini'],
            ['name' => 'Eggplant', 'name_ar' => 'باذنجان', 'normalized_name' => 'eggplant'],
            ['name' => 'Broccoli', 'name_ar' => 'بروكلي', 'normalized_name' => 'broccoli'],
            ['name' => 'Cauliflower', 'name_ar' => 'قرنبيط', 'normalized_name' => 'cauliflower'],
            ['name' => 'Lemon', 'name_ar' => 'ليمون', 'normalized_name' => 'lemon'],
            ['name' => 'Lime', 'name_ar' => 'ليمون أخضر', 'normalized_name' => 'lime'],
            ['name' => 'Cilantro', 'name_ar' => 'كزبرة', 'normalized_name' => 'cilantro'],
            ['name' => 'Dill', 'name_ar' => 'شبت', 'normalized_name' => 'dill'],
            ['name' => 'Coriander', 'name_ar' => 'كزبرة', 'normalized_name' => 'coriander'],
            ['name' => 'Spinach', 'name_ar' => 'سبانخ', 'normalized_name' => 'spinach'],
            ['name' => 'Arugula', 'name_ar' => 'جرجير', 'normalized_name' => 'arugula'],
            ['name' => 'Bulgur', 'name_ar' => 'برغل', 'normalized_name' => 'bulgur'],
            ['name' => 'Couscous', 'name_ar' => 'كسكس', 'normalized_name' => 'couscous'],
            ['name' => 'Tortilla', 'name_ar' => 'تورتيلا', 'normalized_name' => 'tortilla'],
            ['name' => 'Labneh', 'name_ar' => 'لبنة', 'normalized_name' => 'labneh'],
            ['name' => 'Ricotta', 'name_ar' => 'ريكوتا', 'normalized_name' => 'ricotta'],
        ];

        $inserted = 0;
        $linked = 0;

        foreach ($rows as $row) {
            $globalIngredient = GlobalIngredient::query()->firstOrNew([
                'normalized_name' => $row['normalized_name'],
            ]);

            if (! $globalIngredient->exists) {
                $globalIngredient->uuid = (string) Str::uuid();
                $inserted++;
            }

            $globalIngredient->name = $row['name'];
            $globalIngredient->name_ar = $row['name_ar'];
            $globalIngredient->storage_disk = $globalIngredient->storage_disk ?: 'public';

            if (! $globalIngredient->exists) {
                $globalIngredient->file_path = null;
                $globalIngredient->source_file_name = null;
                $globalIngredient->file_size = null;
                $globalIngredient->mime_type = null;
            }

            $globalIngredient->save();

            $updated = Ingredient::query()
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($row['name'])])
                ->where(function ($query) use ($globalIngredient): void {
                    $query->whereNull('global_ingredient_id')
                        ->orWhere('global_ingredient_id', '!=', $globalIngredient->id);
                })
                ->update([
                    'global_ingredient_id' => $globalIngredient->id,
                    'name_ar' => $row['name_ar'],
                    'updated_at' => now(),
                ]);

            $linked += $updated;
        }

        $this->command?->info("EssentialGlobalIngredientSeeder inserted {$inserted} global ingredient rows and linked {$linked} ingredient rows.");
    }
}
