<?php

namespace Database\Seeders;

use App\Models\GlobalIngredient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GlobalIngredientCatalogExtensionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['name' => 'Avocado', 'name_ar' => 'أفوكادو', 'normalized_name' => 'avocado'],
            ['name' => 'Baguette', 'name_ar' => 'باغيت', 'normalized_name' => 'baguette'],
            ['name' => 'Breadcrumbs', 'name_ar' => 'فتات الخبز', 'normalized_name' => 'breadcrumbs'],
            ['name' => 'Brownie', 'name_ar' => 'براوني', 'normalized_name' => 'brownie'],
            ['name' => 'Butter', 'name_ar' => 'زبدة', 'normalized_name' => 'butter'],
            ['name' => 'Cabbage', 'name_ar' => 'ملفوف', 'normalized_name' => 'cabbage'],
            ['name' => 'Cheddar Cheese', 'name_ar' => 'جبنة شيدر', 'normalized_name' => 'cheddar cheese'],
            ['name' => 'Chicken Wings', 'name_ar' => 'أجنحة دجاج', 'normalized_name' => 'chicken wings'],
            ['name' => 'Chili Flakes', 'name_ar' => 'رقائق الفلفل الحار', 'normalized_name' => 'chili flakes'],
            ['name' => 'Coffee', 'name_ar' => 'قهوة', 'normalized_name' => 'coffee'],
            ['name' => 'Cream', 'name_ar' => 'كريمة', 'normalized_name' => 'cream'],
            ['name' => 'Croutons', 'name_ar' => 'مكعبات خبز محمصة', 'normalized_name' => 'croutons'],
            ['name' => 'Flour', 'name_ar' => 'طحين', 'normalized_name' => 'flour'],
            ['name' => 'Graham Cracker', 'name_ar' => 'بسكويت غراهام', 'normalized_name' => 'graham cracker'],
            ['name' => 'Jalapeno', 'name_ar' => 'هالبينو', 'normalized_name' => 'jalapeno'],
            ['name' => 'Ladyfingers', 'name_ar' => 'بسكويت ليدي فينغر', 'normalized_name' => 'ladyfingers'],
            ['name' => 'Mango', 'name_ar' => 'مانجو', 'normalized_name' => 'mango'],
            ['name' => 'Mayonnaise', 'name_ar' => 'مايونيز', 'normalized_name' => 'mayonnaise'],
            ['name' => 'Milk', 'name_ar' => 'حليب', 'normalized_name' => 'milk'],
            ['name' => 'Mozzarella Cheese', 'name_ar' => 'جبنة موزاريلا', 'normalized_name' => 'mozzarella cheese'],
            ['name' => 'Onion', 'name_ar' => 'بصل', 'normalized_name' => 'onion'],
            ['name' => 'Parmesan Cheese', 'name_ar' => 'جبنة بارميزان', 'normalized_name' => 'parmesan cheese'],
            ['name' => 'Penne Pasta', 'name_ar' => 'مكرونة بيني', 'normalized_name' => 'penne pasta'],
            ['name' => 'Potato', 'name_ar' => 'بطاطا', 'normalized_name' => 'potato'],
            ['name' => 'Quinoa', 'name_ar' => 'كينوا', 'normalized_name' => 'quinoa'],
            ['name' => 'Sandwich Bread', 'name_ar' => 'خبز ساندويتش', 'normalized_name' => 'sandwich bread'],
            ['name' => 'Spaghetti Pasta', 'name_ar' => 'مكرونة سباغيتي', 'normalized_name' => 'spaghetti pasta'],
            ['name' => 'Strawberry', 'name_ar' => 'فراولة', 'normalized_name' => 'strawberry'],
            ['name' => 'Sugar', 'name_ar' => 'سكر', 'normalized_name' => 'sugar'],
            ['name' => 'Tortilla Chips', 'name_ar' => 'رقائق تورتيلا', 'normalized_name' => 'tortilla chips'],
            ['name' => 'Tuna', 'name_ar' => 'تونة', 'normalized_name' => 'tuna'],
            ['name' => 'Turkey Slices', 'name_ar' => 'شرائح ديك رومي', 'normalized_name' => 'turkey slices'],
        ];

        $inserted = 0;

        foreach ($rows as $row) {
            $ingredient = GlobalIngredient::query()->firstOrNew([
                'normalized_name' => $row['normalized_name'],
            ]);

            if (! $ingredient->exists) {
                $ingredient->uuid = (string) Str::uuid();
                $inserted++;
            }

            $ingredient->name = $row['name'];
            $ingredient->name_ar = $row['name_ar'];
            $ingredient->storage_disk = 'public';

            if (! $ingredient->exists) {
                $ingredient->file_path = null;
                $ingredient->source_file_name = null;
                $ingredient->file_size = null;
                $ingredient->mime_type = null;
            }

            $ingredient->save();
        }

        $this->command?->info("GlobalIngredientCatalogExtensionSeeder inserted {$inserted} new rows.");
    }
}
