<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dish extends Model
{
    use SoftDeletes;

    private ?bool $cachedOrderable = null;

    protected $appends = [
        'model_state',
        'is_model_ready',
    ];

    protected $fillable = [
        'uuid',
        'restaurant_id',
        'name',
        'name_ar',
        'description',
        'description_ar',
        'price',
        'currency',
        'calories',
        'category',
        'category_ar',
        'status',
        'is_anchor',
        'is_profitable',
        'image_url',
    ];

    protected $casts = [
        'uuid' => 'string',
        'price' => 'decimal:2',
        'currency' => 'string',
        'calories' => 'integer',
        'is_anchor' => 'boolean',
        'is_profitable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DishAsset::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(Scan::class)->latestOfMany();
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function dishIngredients(): HasMany
    {
        return $this->hasMany(DishIngredient::class);
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'dish_ingredients')
            ->withPivot(['quantity', 'unit'])
            ->withTimestamps();
    }

    public function suggestedDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_suggestions',
            'dish_id',
            'suggested_dish_id'
        )->withTimestamps();
    }

    public function suggestedByDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_suggestions',
            'suggested_dish_id',
            'dish_id'
        )->withTimestamps();
    }

    public function relatedDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_related_dishes',
            'dish_id',
            'related_dish_id'
        )->withTimestamps();
    }

    public function relatedByDishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_related_dishes',
            'related_dish_id',
            'dish_id'
        )->withTimestamps();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withTrashed()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }

    public function getIsModelReadyAttribute(): bool
    {
        $assets = $this->relationLoaded('assets')
            ? $this->assets
            : $this->assets()->get();

        return $assets->contains(fn (DishAsset $asset) => $asset->asset_type === 'glb');
    }

    public function getModelStateAttribute(): string
    {
        if ($this->is_model_ready) {
            return 'ready';
        }

        $latestScan = $this->relationLoaded('latestScan')
            ? $this->latestScan
            : $this->latestScan()->first();

        if (! $latestScan) {
            return 'none';
        }

        return match ($latestScan->status) {
            'draft', 'uploaded', 'uploading', 'processing' => 'processing',
            'error', 'canceled' => 'error',
            default => 'none',
        };
    }

    public function toLocalizedArray(?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();
        $attributes = $this->toArray();
        $attributes['is_orderable'] = $this->isOrderable();
        $attributes['is_out_of_stock'] = ! $attributes['is_orderable'];

        if ($locale !== 'ar') {
            return $attributes;
        }

        $attributes['name'] = $this->name_ar ?: $this->translateDishNameToArabic($this->name);
        $attributes['description'] = $this->description_ar ?: $this->translateDescriptionToArabic($this->description);
        $attributes['category'] = $this->category_ar ?: $this->translateCategoryToArabic($this->category);

        return $attributes;
    }

    public function isOrderable(): bool
    {
        if ($this->cachedOrderable !== null) {
            return $this->cachedOrderable;
        }

        $dishIngredients = $this->dishIngredientsWithIngredients();

        foreach ($dishIngredients as $dishIngredient) {
            $ingredient = $dishIngredient->ingredient;

            if (! $ingredient || ! $ingredient->is_active) {
                continue;
            }

            $requiredQuantity = round((float) $dishIngredient->quantity, 3);
            if ($requiredQuantity <= 0) {
                continue;
            }

            if ($dishIngredient->unit !== $ingredient->stock_unit) {
                $this->cachedOrderable = false;

                return false;
            }

            $availableQuantity = round((float) $ingredient->current_stock_quantity, 3);
            if ($availableQuantity < $requiredQuantity) {
                $this->cachedOrderable = false;

                return false;
            }
        }

        $this->cachedOrderable = true;

        return true;
    }

    private function dishIngredientsWithIngredients(): EloquentCollection
    {
        $dishIngredients = $this->relationLoaded('dishIngredients')
            ? $this->dishIngredients
            : $this->dishIngredients()->with('ingredient')->get();

        $dishIngredients->loadMissing('ingredient');

        return $dishIngredients;
    }

    private function translateDishNameToArabic(?string $name): ?string
    {
        if (! $name) {
            return $name;
        }

        if (! preg_match('/^(.*?)(\s+\d{3})$/u', $name, $matches)) {
            return $this->dishNameTranslations()[$name] ?? $name;
        }

        $baseName = trim($matches[1]);
        $suffix = $matches[2];

        return ($this->dishNameTranslations()[$baseName] ?? $baseName) . $suffix;
    }

    private function translateDescriptionToArabic(?string $description): ?string
    {
        if (! $description) {
            return $description;
        }

        if (! preg_match('/^\[dummy-dishes-seeder\] Seeded sample dish (\d+) for (.+) in the (.+) category\. Prepared with (.+)\.$/', $description, $matches)) {
            return $description;
        }

        $sequence = $matches[1];
        $restaurantName = $matches[2];
        $category = $matches[3];
        $ingredients = array_map('trim', explode(',', $matches[4]));
        $translatedIngredients = array_map(
            fn (string $ingredient) => $this->ingredientTranslations()[$ingredient] ?? $ingredient,
            $ingredients
        );

        return sprintf(
            '[dummy-dishes-seeder] طبق تجريبي رقم %s لمطعم %s ضمن فئة %s. يُحضَّر باستخدام %s.',
            $sequence,
            $restaurantName,
            $this->translateCategoryToArabic($category),
            implode('، ', $translatedIngredients)
        );
    }

    private function translateCategoryToArabic(?string $category): ?string
    {
        if (! $category) {
            return $category;
        }

        return $this->categoryTranslations()[$category] ?? $category;
    }

    private function categoryTranslations(): array
    {
        return [
            'Pizza' => 'بيتزا',
            'Specialty Pizza' => 'بيتزا خاصة',
            'Burgers' => 'برغر',
            'Sandwiches' => 'ساندويتشات',
            'Pasta' => 'باستا',
            'Salads' => 'سلطات',
            'Appetizers' => 'مقبلات',
            'Sides' => 'أطباق جانبية',
            'Desserts' => 'حلويات',
            'Drinks' => 'مشروبات',
        ];
    }

    private function dishNameTranslations(): array
    {
        return [
            'Margherita Pizza' => 'بيتزا مارغريتا',
            'Pepperoni Pizza' => 'بيتزا بيبروني',
            'Vegetarian Pizza' => 'بيتزا نباتية',
            'Four Cheese Pizza' => 'بيتزا الأربع أجبان',
            'BBQ Chicken Pizza' => 'بيتزا دجاج باربكيو',
            'Buffalo Chicken Pizza' => 'بيتزا دجاج بافلو',
            'Truffle Mushroom Pizza' => 'بيتزا الفطر بالكمأة',
            'Meat Lovers Pizza' => 'بيتزا عشاق اللحوم',
            'Classic Beef Burger' => 'برغر لحم كلاسيكي',
            'Mushroom Swiss Burger' => 'برغر مشروم وسويس',
            'Spicy Jalapeño Burger' => 'برغر هالبينو حار',
            'Crispy Chicken Burger' => 'برغر دجاج مقرمش',
            'Grilled Chicken Sandwich' => 'ساندويتش دجاج مشوي',
            'Turkey Club Sandwich' => 'ساندويتش كلوب ديك رومي',
            'Philly Cheesesteak' => 'فيلي تشيزستيك',
            'Tuna Melt Sandwich' => 'ساندويتش تونا ميلت',
            'Chicken Alfredo Pasta' => 'باستا ألفريدو بالدجاج',
            'Spaghetti Bolognese' => 'سباغيتي بولونيز',
            'Pesto Penne Pasta' => 'باستا بيني بالبيستو',
            'Shrimp Arrabbiata' => 'أرابياتا بالروبيان',
            'Caesar Salad' => 'سلطة سيزر',
            'Greek Salad' => 'سلطة يونانية',
            'Grilled Chicken Salad' => 'سلطة دجاج مشوي',
            'Avocado Quinoa Salad' => 'سلطة كينوا بالأفوكادو',
            'Mozzarella Sticks' => 'أصابع موزاريلا',
            'Chicken Wings' => 'أجنحة دجاج',
            'Loaded Nachos' => 'ناتشوز محملة',
            'Garlic Bread' => 'خبز بالثوم',
            'French Fries' => 'بطاطا مقلية',
            'Cheesy Fries' => 'بطاطا بالجبنة',
            'Onion Rings' => 'حلقات بصل',
            'Coleslaw' => 'كولسلو',
            'Chocolate Lava Cake' => 'كيكة لافا بالشوكولاتة',
            'New York Cheesecake' => 'تشيزكيك نيويورك',
            'Tiramisu' => 'تيراميسو',
            'Brownie Sundae' => 'براوني صنداي',
            'Fresh Lemon Mint' => 'ليمون نعناع طازج',
            'Iced Coffee' => 'قهوة مثلجة',
            'Strawberry Milkshake' => 'ميلك شيك الفراولة',
            'Mango Smoothie' => 'سموثي المانجو',
        ];
    }

    private function ingredientTranslations(): array
    {
        return [
            'pizza dough' => 'عجينة بيتزا',
            'tomato sauce' => 'صلصة طماطم',
            'mozzarella' => 'موزاريلا',
            'fresh basil' => 'ريحان طازج',
            'olive oil' => 'زيت زيتون',
            'pepperoni' => 'بيبروني',
            'oregano' => 'أوريغانو',
            'mushrooms' => 'فطر',
            'bell peppers' => 'فليفلة حلوة',
            'olives' => 'زيتون',
            'red onions' => 'بصل أحمر',
            'parmesan' => 'بارميزان',
            'gorgonzola' => 'غورغونزولا',
            'cheddar' => 'شيدر',
            'bbq sauce' => 'صلصة باربكيو',
            'grilled chicken' => 'دجاج مشوي',
            'cilantro' => 'كزبرة',
            'buffalo sauce' => 'صلصة بافلو',
            'chicken' => 'دجاج',
            'ranch drizzle' => 'صلصة رانش',
            'cream sauce' => 'صلصة كريمية',
            'truffle oil' => 'زيت الكمأة',
            'sausage' => 'نقانق',
            'beef bacon' => 'بيف بيكون',
            'beef patty' => 'قطعة لحم بقري',
            'burger bun' => 'خبز برغر',
            'lettuce' => 'خس',
            'tomato' => 'طماطم',
            'pickles' => 'مخلل',
            'burger sauce' => 'صلصة برغر',
            'swiss cheese' => 'جبنة سويسرية',
            'caramelized onions' => 'بصل مكرمل',
            'mayonnaise' => 'مايونيز',
            'jalapeños' => 'هالبينو',
            'pepper jack cheese' => 'جبنة بيبر جاك',
            'spicy mayo' => 'مايونيز حار',
            'fried chicken fillet' => 'فيليه دجاج مقرمش',
            'garlic mayo' => 'مايونيز بالثوم',
            'ciabatta bread' => 'خبز تشاباتا',
            'garlic aioli' => 'آيولي بالثوم',
            'turkey' => 'ديك رومي',
            'toast bread' => 'خبز توست',
            'beef strips' => 'شرائح لحم بقري',
            'hoagie roll' => 'خبز هوجي',
            'onions' => 'بصل',
            'provolone' => 'بروفولون',
            'tuna' => 'تونا',
            'fettuccine' => 'فيتوتشيني',
            'cream' => 'كريمة',
            'garlic' => 'ثوم',
            'butter' => 'زبدة',
            'spaghetti' => 'سباغيتي',
            'ground beef' => 'لحم بقري مفروم',
            'basil pesto' => 'بيستو الريحان',
            'cherry tomatoes' => 'طماطم كرزية',
            'penne' => 'بيني',
            'shrimp' => 'روبيان',
            'chili flakes' => 'رقائق فلفل حار',
            'parsley' => 'بقدونس',
            'romaine lettuce' => 'خس روماني',
            'croutons' => 'خبز محمص',
            'caesar dressing' => 'صلصة سيزر',
            'cucumber' => 'خيار',
            'feta' => 'فيتا',
            'mixed greens' => 'خضار مشكلة',
            'corn' => 'ذرة',
            'vinaigrette' => 'صلصة فينيغريت',
            'quinoa' => 'كينوا',
            'avocado' => 'أفوكادو',
            'lemon dressing' => 'صلصة ليمون',
            'breadcrumbs' => 'بقسماط',
            'eggs' => 'بيض',
            'flour' => 'طحين',
            'marinara sauce' => 'صلصة مارينارا',
            'chicken wings' => 'أجنحة دجاج',
            'tortilla chips' => 'رقائق تورتيلا',
            'salsa' => 'سالسا',
            'guacamole' => 'غواكامولي',
            'sour cream' => 'كريمة حامضة',
            'baguette' => 'باغيت',
            'garlic butter' => 'زبدة بالثوم',
            'potatoes' => 'بطاطا',
            'salt' => 'ملح',
            'vegetable oil' => 'زيت نباتي',
            'cheddar sauce' => 'صلصة شيدر',
            'oil' => 'زيت',
            'cabbage' => 'ملفوف',
            'carrots' => 'جزر',
            'vinegar' => 'خل',
            'sugar' => 'سكر',
            'dark chocolate' => 'شوكولاتة داكنة',
            'cream cheese' => 'جبنة كريمية',
            'biscuits' => 'بسكويت',
            'mascarpone' => 'ماسكاربوني',
            'ladyfingers' => 'أصابع بسكويت',
            'espresso' => 'إسبريسو',
            'cocoa powder' => 'بودرة كاكاو',
            'brownie' => 'براوني',
            'vanilla ice cream' => 'آيس كريم فانيلا',
            'chocolate sauce' => 'صلصة شوكولاتة',
            'nuts' => 'مكسرات',
            'lemon juice' => 'عصير ليمون',
            'mint' => 'نعناع',
            'sugar syrup' => 'شراب السكر',
            'ice water' => 'ماء مثلج',
            'milk' => 'حليب',
            'ice' => 'ثلج',
            'strawberries' => 'فراولة',
            'mango' => 'مانجو',
            'yogurt' => 'زبادي',
            'honey' => 'عسل',
        ];
    }
}
