<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\Restaurant;
use App\Models\RestaurantDomain;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RealWorldTenantScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $alphaOwner = $this->upsertOwner(
            name: 'Alpha Owner',
            email: 'alpha.owner@rozer.fun',
            password: 'alpha12345'
        );

        $sigmaOwner = $this->upsertOwner(
            name: 'Sigma Owner',
            email: 'sigma.owner@rozer.fun',
            password: 'sigma12345'
        );

        $alphaRestaurant = $this->upsertRestaurant(
            owner: $alphaOwner,
            slug: 'alph',
            name: 'Alpha Bistro',
            description: 'Modern Mediterranean bistro focused on fresh produce, grilled proteins, and handcrafted mocktails.',
            address: 'Hamra District, Beirut'
        );

        $sigmaRestaurant = $this->upsertRestaurant(
            owner: $sigmaOwner,
            slug: 'sigma',
            name: 'Sigma Fusion Kitchen',
            description: 'Asian-fusion kitchen with wok dishes, ramen bowls, and signature tea-based drinks.',
            address: 'Mar Mikhael, Beirut'
        );

        $this->upsertDomain($alphaRestaurant, 'alpha.rozer.fun');
        $this->upsertDomain($sigmaRestaurant, 'sigma.rozer.fun');

        $alphaIngredientMap = $this->seedRestaurantIngredients($alphaRestaurant, [
            ['name' => 'Chicken Breast', 'name_ar' => 'صدر دجاج', 'unit' => 'g', 'stock' => 22000, 'low' => 5000],
            ['name' => 'Beef Sirloin', 'name_ar' => 'لحم بقري سيرلوين', 'unit' => 'g', 'stock' => 18000, 'low' => 4000],
            ['name' => 'Salmon Fillet', 'name_ar' => 'فيليه سلمون', 'unit' => 'g', 'stock' => 14000, 'low' => 3500],
            ['name' => 'Shrimp', 'name_ar' => 'روبيان', 'unit' => 'g', 'stock' => 12000, 'low' => 3000],
            ['name' => 'Halloumi Cheese', 'name_ar' => 'جبنة حلوم', 'unit' => 'g', 'stock' => 9000, 'low' => 2000],
            ['name' => 'Feta Cheese', 'name_ar' => 'جبنة فيتا', 'unit' => 'g', 'stock' => 8000, 'low' => 1800],
            ['name' => 'Greek Yogurt', 'name_ar' => 'لبن يوناني', 'unit' => 'g', 'stock' => 10000, 'low' => 2200],
            ['name' => 'Chickpea', 'name_ar' => 'حمص حب', 'unit' => 'g', 'stock' => 16000, 'low' => 3500],
            ['name' => 'Basmati Rice', 'name_ar' => 'أرز بسمتي', 'unit' => 'g', 'stock' => 26000, 'low' => 6000],
            ['name' => 'Pita Bread', 'name_ar' => 'خبز بيتا', 'unit' => 'piece', 'stock' => 240, 'low' => 60],
            ['name' => 'Lettuce', 'name_ar' => 'خس', 'unit' => 'g', 'stock' => 14000, 'low' => 3000],
            ['name' => 'Tomato', 'name_ar' => 'بندورة', 'unit' => 'g', 'stock' => 18000, 'low' => 3500],
            ['name' => 'Cucumber', 'name_ar' => 'خيار', 'unit' => 'g', 'stock' => 15000, 'low' => 3000],
            ['name' => 'Red Onion', 'name_ar' => 'بصل أحمر', 'unit' => 'g', 'stock' => 9000, 'low' => 1800],
            ['name' => 'Parsley', 'name_ar' => 'بقدونس', 'unit' => 'g', 'stock' => 5000, 'low' => 1000],
            ['name' => 'Mint', 'name_ar' => 'نعناع', 'unit' => 'g', 'stock' => 4000, 'low' => 800],
            ['name' => 'Lemon Juice', 'name_ar' => 'عصير ليمون', 'unit' => 'ml', 'stock' => 12000, 'low' => 2500],
            ['name' => 'Olive Oil', 'name_ar' => 'زيت زيتون', 'unit' => 'ml', 'stock' => 18000, 'low' => 3000],
            ['name' => 'Garlic', 'name_ar' => 'ثوم', 'unit' => 'g', 'stock' => 3500, 'low' => 700],
            ['name' => 'Tahini', 'name_ar' => 'طحينة', 'unit' => 'g', 'stock' => 6000, 'low' => 1200],
            ['name' => 'Pomegranate Molasses', 'name_ar' => 'دبس رمان', 'unit' => 'ml', 'stock' => 4000, 'low' => 900],
            ['name' => 'Orange Juice', 'name_ar' => 'عصير برتقال', 'unit' => 'ml', 'stock' => 9000, 'low' => 1800],
            ['name' => 'Sugar Syrup', 'name_ar' => 'شراب سكر', 'unit' => 'ml', 'stock' => 6000, 'low' => 1200],
            ['name' => 'Sparkling Water', 'name_ar' => 'مياه غازية', 'unit' => 'ml', 'stock' => 15000, 'low' => 3000],
            ['name' => 'Ice Cube', 'name_ar' => 'مكعبات ثلج', 'unit' => 'piece', 'stock' => 8000, 'low' => 1500],
        ]);

        $sigmaIngredientMap = $this->seedRestaurantIngredients($sigmaRestaurant, [
            ['name' => 'Chicken Thigh', 'name_ar' => 'فخذ دجاج', 'unit' => 'g', 'stock' => 22000, 'low' => 5000],
            ['name' => 'Beef Tenderloin', 'name_ar' => 'لحم بقري فيليه', 'unit' => 'g', 'stock' => 18000, 'low' => 4000],
            ['name' => 'Salmon Fillet', 'name_ar' => 'فيليه سلمون', 'unit' => 'g', 'stock' => 12000, 'low' => 2800],
            ['name' => 'Shrimp', 'name_ar' => 'روبيان', 'unit' => 'g', 'stock' => 13000, 'low' => 3000],
            ['name' => 'Tofu', 'name_ar' => 'توفو', 'unit' => 'g', 'stock' => 10000, 'low' => 2200],
            ['name' => 'Egg Noodle', 'name_ar' => 'نودلز البيض', 'unit' => 'g', 'stock' => 26000, 'low' => 6000],
            ['name' => 'Ramen Noodle', 'name_ar' => 'نودلز رامن', 'unit' => 'g', 'stock' => 24000, 'low' => 5500],
            ['name' => 'Jasmine Rice', 'name_ar' => 'أرز ياسمين', 'unit' => 'g', 'stock' => 28000, 'low' => 6500],
            ['name' => 'Soy Sauce', 'name_ar' => 'صلصة الصويا', 'unit' => 'ml', 'stock' => 15000, 'low' => 3000],
            ['name' => 'Sesame Oil', 'name_ar' => 'زيت السمسم', 'unit' => 'ml', 'stock' => 7000, 'low' => 1500],
            ['name' => 'Miso Paste', 'name_ar' => 'معجون الميسو', 'unit' => 'g', 'stock' => 8000, 'low' => 1700],
            ['name' => 'Coconut Milk', 'name_ar' => 'حليب جوز الهند', 'unit' => 'ml', 'stock' => 12000, 'low' => 2500],
            ['name' => 'Mushroom', 'name_ar' => 'فطر', 'unit' => 'g', 'stock' => 14000, 'low' => 3000],
            ['name' => 'Bell Pepper', 'name_ar' => 'فليفلة', 'unit' => 'g', 'stock' => 13000, 'low' => 2800],
            ['name' => 'Carrot', 'name_ar' => 'جزر', 'unit' => 'g', 'stock' => 12000, 'low' => 2500],
            ['name' => 'Spring Onion', 'name_ar' => 'بصل أخضر', 'unit' => 'g', 'stock' => 5000, 'low' => 1000],
            ['name' => 'Ginger', 'name_ar' => 'زنجبيل', 'unit' => 'g', 'stock' => 3500, 'low' => 700],
            ['name' => 'Garlic', 'name_ar' => 'ثوم', 'unit' => 'g', 'stock' => 3800, 'low' => 700],
            ['name' => 'Lime Juice', 'name_ar' => 'عصير لايم', 'unit' => 'ml', 'stock' => 9000, 'low' => 1800],
            ['name' => 'Chili Sauce', 'name_ar' => 'صلصة فلفل حار', 'unit' => 'ml', 'stock' => 6000, 'low' => 1200],
            ['name' => 'Honey', 'name_ar' => 'عسل', 'unit' => 'g', 'stock' => 5000, 'low' => 1000],
            ['name' => 'Green Tea', 'name_ar' => 'شاي أخضر', 'unit' => 'g', 'stock' => 3000, 'low' => 600],
            ['name' => 'Black Tea', 'name_ar' => 'شاي أسود', 'unit' => 'g', 'stock' => 3000, 'low' => 600],
            ['name' => 'Sugar Syrup', 'name_ar' => 'شراب سكر', 'unit' => 'ml', 'stock' => 7000, 'low' => 1200],
            ['name' => 'Sparkling Water', 'name_ar' => 'مياه غازية', 'unit' => 'ml', 'stock' => 14000, 'low' => 2800],
            ['name' => 'Ice Cube', 'name_ar' => 'مكعبات ثلج', 'unit' => 'piece', 'stock' => 7000, 'low' => 1400],
        ]);

        $this->seedRestaurantDishes($alphaRestaurant, $alphaIngredientMap, [
            [
                'name' => 'Lemon Chicken Souvlaki Bowl',
                'name_ar' => 'باول سوفلاكي دجاج بالليمون',
                'description' => 'Char-grilled lemon-marinated chicken breast over basmati rice with cucumber tomato salad and garlic yogurt.',
                'description_ar' => 'دجاج متبل بالليمون ومشوي على الفحم فوق أرز بسمتي مع سلطة خيار وبندورة وصلصة لبن بالثوم.',
                'category' => 'Mains',
                'category_ar' => 'أطباق رئيسية',
                'price' => 13.50,
                'calories' => 640,
                'image_url' => 'https://images.pexels.com/photos/70497/pexels-photo-70497.jpeg',
                'recipe' => [
                    ['ingredient' => 'Chicken Breast', 'qty' => 180, 'unit' => 'g'],
                    ['ingredient' => 'Basmati Rice', 'qty' => 160, 'unit' => 'g'],
                    ['ingredient' => 'Greek Yogurt', 'qty' => 40, 'unit' => 'g'],
                    ['ingredient' => 'Lemon Juice', 'qty' => 25, 'unit' => 'ml'],
                    ['ingredient' => 'Olive Oil', 'qty' => 18, 'unit' => 'ml'],
                    ['ingredient' => 'Garlic', 'qty' => 6, 'unit' => 'g'],
                    ['ingredient' => 'Cucumber', 'qty' => 35, 'unit' => 'g'],
                    ['ingredient' => 'Tomato', 'qty' => 45, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Halloumi Avocado Pita',
                'name_ar' => 'بيتا حلوم وأفوكادو',
                'description' => 'Grilled halloumi, avocado spread, tomato, and crisp lettuce in warm pita bread.',
                'description_ar' => 'حلوم مشوي مع كريمة أفوكادو وبندورة وخس مقرمش داخل خبز بيتا دافئ.',
                'category' => 'Sandwiches',
                'category_ar' => 'ساندويشات',
                'price' => 10.50,
                'calories' => 520,
                'image_url' => 'https://images.pexels.com/photos/2092507/pexels-photo-2092507.jpeg',
                'recipe' => [
                    ['ingredient' => 'Halloumi Cheese', 'qty' => 120, 'unit' => 'g'],
                    ['ingredient' => 'Pita Bread', 'qty' => 1, 'unit' => 'piece'],
                    ['ingredient' => 'Lettuce', 'qty' => 30, 'unit' => 'g'],
                    ['ingredient' => 'Tomato', 'qty' => 40, 'unit' => 'g'],
                    ['ingredient' => 'Olive Oil', 'qty' => 10, 'unit' => 'ml'],
                ],
            ],
            [
                'name' => 'Seared Salmon Tabbouleh Plate',
                'name_ar' => 'طبق سلمون مشوي مع تبولة',
                'description' => 'Pan-seared salmon with parsley tabbouleh, cucumber, tomato, and citrus olive dressing.',
                'description_ar' => 'فيليه سلمون محمر مع تبولة بقدونس وخيار وبندورة وصلصة زيت زيتون حمضية.',
                'category' => 'Mains',
                'category_ar' => 'أطباق رئيسية',
                'price' => 18.00,
                'calories' => 590,
                'image_url' => 'https://images.pexels.com/photos/262959/pexels-photo-262959.jpeg',
                'recipe' => [
                    ['ingredient' => 'Salmon Fillet', 'qty' => 180, 'unit' => 'g'],
                    ['ingredient' => 'Parsley', 'qty' => 35, 'unit' => 'g'],
                    ['ingredient' => 'Cucumber', 'qty' => 30, 'unit' => 'g'],
                    ['ingredient' => 'Tomato', 'qty' => 40, 'unit' => 'g'],
                    ['ingredient' => 'Red Onion', 'qty' => 12, 'unit' => 'g'],
                    ['ingredient' => 'Lemon Juice', 'qty' => 20, 'unit' => 'ml'],
                    ['ingredient' => 'Olive Oil', 'qty' => 14, 'unit' => 'ml'],
                ],
            ],
            [
                'name' => 'Shrimp Tahini Rice',
                'name_ar' => 'أرز روبيان بصلصة الطحينة',
                'description' => 'Sautéed shrimp over fragrant rice with light tahini lemon sauce and fresh herbs.',
                'description_ar' => 'روبيان مشوح فوق أرز عطري مع صلصة طحينة خفيفة بالليمون وأعشاب طازجة.',
                'category' => 'Mains',
                'category_ar' => 'أطباق رئيسية',
                'price' => 16.25,
                'calories' => 610,
                'image_url' => 'https://images.pexels.com/photos/725997/pexels-photo-725997.jpeg',
                'recipe' => [
                    ['ingredient' => 'Shrimp', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Basmati Rice', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Tahini', 'qty' => 28, 'unit' => 'g'],
                    ['ingredient' => 'Lemon Juice', 'qty' => 18, 'unit' => 'ml'],
                    ['ingredient' => 'Garlic', 'qty' => 5, 'unit' => 'g'],
                    ['ingredient' => 'Parsley', 'qty' => 10, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Feta Chickpea Garden Salad',
                'name_ar' => 'سلطة حمص وحديقة مع فيتا',
                'description' => 'Chickpeas, feta, cucumber, tomato, lettuce, mint, and lemon olive dressing.',
                'description_ar' => 'حمص مسلوق مع جبنة فيتا وخيار وبندورة وخس ونعناع مع تتبيلة ليمون وزيت زيتون.',
                'category' => 'Salads',
                'category_ar' => 'سلطات',
                'price' => 9.75,
                'calories' => 430,
                'image_url' => 'https://images.pexels.com/photos/257816/pexels-photo-257816.jpeg',
                'recipe' => [
                    ['ingredient' => 'Chickpea', 'qty' => 140, 'unit' => 'g'],
                    ['ingredient' => 'Feta Cheese', 'qty' => 60, 'unit' => 'g'],
                    ['ingredient' => 'Cucumber', 'qty' => 60, 'unit' => 'g'],
                    ['ingredient' => 'Tomato', 'qty' => 70, 'unit' => 'g'],
                    ['ingredient' => 'Lettuce', 'qty' => 45, 'unit' => 'g'],
                    ['ingredient' => 'Mint', 'qty' => 6, 'unit' => 'g'],
                    ['ingredient' => 'Lemon Juice', 'qty' => 16, 'unit' => 'ml'],
                    ['ingredient' => 'Olive Oil', 'qty' => 14, 'unit' => 'ml'],
                ],
            ],
            [
                'name' => 'Pomegranate Beef Skillet',
                'name_ar' => 'مقلاة لحم بدبس الرمان',
                'description' => 'Tender beef strips sautéed with red onions, tomato, and pomegranate molasses glaze.',
                'description_ar' => 'شرائح لحم بقري طرية مشوحة مع بصل أحمر وبندورة وتغليفة دبس الرمان.',
                'category' => 'Mains',
                'category_ar' => 'أطباق رئيسية',
                'price' => 17.50,
                'calories' => 670,
                'image_url' => 'https://images.pexels.com/photos/1640777/pexels-photo-1640777.jpeg',
                'recipe' => [
                    ['ingredient' => 'Beef Sirloin', 'qty' => 190, 'unit' => 'g'],
                    ['ingredient' => 'Red Onion', 'qty' => 35, 'unit' => 'g'],
                    ['ingredient' => 'Tomato', 'qty' => 55, 'unit' => 'g'],
                    ['ingredient' => 'Pomegranate Molasses', 'qty' => 20, 'unit' => 'ml'],
                    ['ingredient' => 'Olive Oil', 'qty' => 12, 'unit' => 'ml'],
                    ['ingredient' => 'Garlic', 'qty' => 5, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Citrus Mint Sparkler',
                'name_ar' => 'مشروب حمضيات ونعناع فوار',
                'description' => 'Fresh lemon and orange blend with mint, sugar syrup, sparkling water, and ice.',
                'description_ar' => 'مزيج ليمون وبرتقال طازج مع نعناع وشراب السكر ومياه غازية وثلج.',
                'category' => 'Drinks',
                'category_ar' => 'مشروبات',
                'price' => 4.75,
                'calories' => 150,
                'image_url' => 'https://images.pexels.com/photos/96974/pexels-photo-96974.jpeg',
                'recipe' => [
                    ['ingredient' => 'Lemon Juice', 'qty' => 30, 'unit' => 'ml'],
                    ['ingredient' => 'Orange Juice', 'qty' => 70, 'unit' => 'ml'],
                    ['ingredient' => 'Sugar Syrup', 'qty' => 18, 'unit' => 'ml'],
                    ['ingredient' => 'Sparkling Water', 'qty' => 180, 'unit' => 'ml'],
                    ['ingredient' => 'Mint', 'qty' => 4, 'unit' => 'g'],
                    ['ingredient' => 'Ice Cube', 'qty' => 8, 'unit' => 'piece'],
                ],
            ],
            [
                'name' => 'House Lemonade',
                'name_ar' => 'ليمونادة المنزل',
                'description' => 'Classic chilled lemonade made with fresh lemons, light syrup, and crushed ice.',
                'description_ar' => 'ليمونادة باردة كلاسيكية محضرة من ليمون طازج وشراب خفيف وثلج مجروش.',
                'category' => 'Drinks',
                'category_ar' => 'مشروبات',
                'price' => 3.90,
                'calories' => 120,
                'image_url' => 'https://images.pexels.com/photos/96974/pexels-photo-96974.jpeg',
                'recipe' => [
                    ['ingredient' => 'Lemon Juice', 'qty' => 35, 'unit' => 'ml'],
                    ['ingredient' => 'Sugar Syrup', 'qty' => 16, 'unit' => 'ml'],
                    ['ingredient' => 'Ice Cube', 'qty' => 7, 'unit' => 'piece'],
                ],
            ],
        ]);

        $this->seedRestaurantDishes($sigmaRestaurant, $sigmaIngredientMap, [
            [
                'name' => 'Miso Chicken Ramen',
                'name_ar' => 'رامن دجاج بالميسو',
                'description' => 'Slow-simmered miso broth with ramen noodles, grilled chicken thigh, mushrooms, and spring onion.',
                'description_ar' => 'مرق ميسو مطهو ببطء مع نودلز رامن وفخذ دجاج مشوي وفطر وبصل أخضر.',
                'category' => 'Bowls',
                'category_ar' => 'أطباق بول',
                'price' => 14.25,
                'calories' => 710,
                'image_url' => 'https://images.pexels.com/photos/884600/pexels-photo-884600.jpeg',
                'recipe' => [
                    ['ingredient' => 'Ramen Noodle', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Chicken Thigh', 'qty' => 180, 'unit' => 'g'],
                    ['ingredient' => 'Miso Paste', 'qty' => 30, 'unit' => 'g'],
                    ['ingredient' => 'Mushroom', 'qty' => 60, 'unit' => 'g'],
                    ['ingredient' => 'Spring Onion', 'qty' => 14, 'unit' => 'g'],
                    ['ingredient' => 'Garlic', 'qty' => 6, 'unit' => 'g'],
                    ['ingredient' => 'Ginger', 'qty' => 6, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Teriyaki Beef Rice Bowl',
                'name_ar' => 'باول أرز بلحم ترياكي',
                'description' => 'Tender beef strips glazed in soy-honey teriyaki, served on jasmine rice with peppers and onions.',
                'description_ar' => 'شرائح لحم طرية بصلصة ترياكي الصويا والعسل، تقدم مع أرز ياسمين وفليفلة وبصل.',
                'category' => 'Bowls',
                'category_ar' => 'أطباق بول',
                'price' => 16.80,
                'calories' => 760,
                'image_url' => 'https://images.pexels.com/photos/1640774/pexels-photo-1640774.jpeg',
                'recipe' => [
                    ['ingredient' => 'Beef Tenderloin', 'qty' => 190, 'unit' => 'g'],
                    ['ingredient' => 'Jasmine Rice', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Soy Sauce', 'qty' => 30, 'unit' => 'ml'],
                    ['ingredient' => 'Honey', 'qty' => 16, 'unit' => 'g'],
                    ['ingredient' => 'Bell Pepper', 'qty' => 50, 'unit' => 'g'],
                    ['ingredient' => 'Carrot', 'qty' => 30, 'unit' => 'g'],
                    ['ingredient' => 'Garlic', 'qty' => 5, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Spicy Shrimp Noodles',
                'name_ar' => 'نودلز روبيان حار',
                'description' => 'Wok-tossed egg noodles with shrimp, chili sauce, garlic, and crunchy vegetables.',
                'description_ar' => 'نودلز بيض مشوحة في الووك مع روبيان وصلصة حارة وثوم وخضار مقرمشة.',
                'category' => 'Noodles',
                'category_ar' => 'نودلز',
                'price' => 15.50,
                'calories' => 690,
                'image_url' => 'https://images.pexels.com/photos/2347311/pexels-photo-2347311.jpeg',
                'recipe' => [
                    ['ingredient' => 'Egg Noodle', 'qty' => 180, 'unit' => 'g'],
                    ['ingredient' => 'Shrimp', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Chili Sauce', 'qty' => 24, 'unit' => 'ml'],
                    ['ingredient' => 'Soy Sauce', 'qty' => 22, 'unit' => 'ml'],
                    ['ingredient' => 'Bell Pepper', 'qty' => 40, 'unit' => 'g'],
                    ['ingredient' => 'Carrot', 'qty' => 35, 'unit' => 'g'],
                    ['ingredient' => 'Garlic', 'qty' => 6, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Coconut Salmon Curry',
                'name_ar' => 'كاري سلمون بجوز الهند',
                'description' => 'Salmon simmered in coconut curry sauce with mushrooms, ginger, and lime over jasmine rice.',
                'description_ar' => 'سلمون مطهو بصلصة كاري جوز الهند مع الفطر والزنجبيل واللايم فوق أرز ياسمين.',
                'category' => 'Curries',
                'category_ar' => 'كاري',
                'price' => 18.40,
                'calories' => 740,
                'image_url' => 'https://images.pexels.com/photos/262959/pexels-photo-262959.jpeg',
                'recipe' => [
                    ['ingredient' => 'Salmon Fillet', 'qty' => 180, 'unit' => 'g'],
                    ['ingredient' => 'Coconut Milk', 'qty' => 140, 'unit' => 'ml'],
                    ['ingredient' => 'Jasmine Rice', 'qty' => 160, 'unit' => 'g'],
                    ['ingredient' => 'Mushroom', 'qty' => 55, 'unit' => 'g'],
                    ['ingredient' => 'Ginger', 'qty' => 7, 'unit' => 'g'],
                    ['ingredient' => 'Lime Juice', 'qty' => 18, 'unit' => 'ml'],
                    ['ingredient' => 'Soy Sauce', 'qty' => 12, 'unit' => 'ml'],
                ],
            ],
            [
                'name' => 'Crispy Tofu Veggie Stir Fry',
                'name_ar' => 'ستير فراي خضار مع توفو مقرمش',
                'description' => 'Golden tofu cubes with bell peppers, carrots, mushrooms, and sesame soy glaze.',
                'description_ar' => 'مكعبات توفو ذهبية مع فليفلة وجزر وفطر وتغليفة صويا وسمسم.',
                'category' => 'Veggie',
                'category_ar' => 'نباتي',
                'price' => 12.90,
                'calories' => 560,
                'image_url' => 'https://images.pexels.com/photos/7937434/pexels-photo-7937434.jpeg',
                'recipe' => [
                    ['ingredient' => 'Tofu', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Bell Pepper', 'qty' => 60, 'unit' => 'g'],
                    ['ingredient' => 'Carrot', 'qty' => 45, 'unit' => 'g'],
                    ['ingredient' => 'Mushroom', 'qty' => 50, 'unit' => 'g'],
                    ['ingredient' => 'Soy Sauce', 'qty' => 26, 'unit' => 'ml'],
                    ['ingredient' => 'Sesame Oil', 'qty' => 12, 'unit' => 'ml'],
                    ['ingredient' => 'Garlic', 'qty' => 5, 'unit' => 'g'],
                ],
            ],
            [
                'name' => 'Honey Chili Chicken Noodles',
                'name_ar' => 'نودلز دجاج بالعسل والفلفل',
                'description' => 'Wok-seared chicken thigh noodles with honey chili glaze, spring onion, and sesame finish.',
                'description_ar' => 'نودلز بدجاج مشوح وتغليفة عسل وفلفل مع بصل أخضر ولمسة سمسم.',
                'category' => 'Noodles',
                'category_ar' => 'نودلز',
                'price' => 14.90,
                'calories' => 700,
                'image_url' => 'https://images.pexels.com/photos/1279330/pexels-photo-1279330.jpeg',
                'recipe' => [
                    ['ingredient' => 'Egg Noodle', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Chicken Thigh', 'qty' => 170, 'unit' => 'g'],
                    ['ingredient' => 'Honey', 'qty' => 18, 'unit' => 'g'],
                    ['ingredient' => 'Chili Sauce', 'qty' => 20, 'unit' => 'ml'],
                    ['ingredient' => 'Soy Sauce', 'qty' => 18, 'unit' => 'ml'],
                    ['ingredient' => 'Spring Onion', 'qty' => 12, 'unit' => 'g'],
                    ['ingredient' => 'Sesame Oil', 'qty' => 10, 'unit' => 'ml'],
                ],
            ],
            [
                'name' => 'Yuzu Green Tea Cooler',
                'name_ar' => 'كولر شاي أخضر ويوزو',
                'description' => 'Iced green tea, citrus lime, light syrup, and sparkling water.',
                'description_ar' => 'شاي أخضر مثلج مع لايم وشراب خفيف ومياه غازية.',
                'category' => 'Drinks',
                'category_ar' => 'مشروبات',
                'price' => 4.60,
                'calories' => 110,
                'image_url' => 'https://images.pexels.com/photos/312418/pexels-photo-312418.jpeg',
                'recipe' => [
                    ['ingredient' => 'Green Tea', 'qty' => 4, 'unit' => 'g'],
                    ['ingredient' => 'Lime Juice', 'qty' => 24, 'unit' => 'ml'],
                    ['ingredient' => 'Sugar Syrup', 'qty' => 16, 'unit' => 'ml'],
                    ['ingredient' => 'Sparkling Water', 'qty' => 170, 'unit' => 'ml'],
                    ['ingredient' => 'Ice Cube', 'qty' => 8, 'unit' => 'piece'],
                ],
            ],
            [
                'name' => 'Iced Thai Milk Tea',
                'name_ar' => 'شاي تايلندي بالحليب مثلج',
                'description' => 'Strong black tea shaken with milk, syrup, and ice for a creamy finish.',
                'description_ar' => 'شاي أسود مركز مخفوق مع حليب وشراب سكر وثلج لنكهة كريمية.',
                'category' => 'Drinks',
                'category_ar' => 'مشروبات',
                'price' => 4.20,
                'calories' => 180,
                'image_url' => 'https://images.pexels.com/photos/1327866/pexels-photo-1327866.jpeg',
                'recipe' => [
                    ['ingredient' => 'Black Tea', 'qty' => 5, 'unit' => 'g'],
                    ['ingredient' => 'Sugar Syrup', 'qty' => 20, 'unit' => 'ml'],
                    ['ingredient' => 'Coconut Milk', 'qty' => 80, 'unit' => 'ml'],
                    ['ingredient' => 'Ice Cube', 'qty' => 8, 'unit' => 'piece'],
                ],
            ],
        ]);

        $this->command?->info('RealWorldTenantScenarioSeeder completed for slugs: alph, sigma.');
    }

    private function upsertOwner(string $name, string $email, string $password): User
    {
        $user = User::query()->firstOrNew(['email' => strtolower($email)]);

        if (! $user->exists) {
            $user->password = Hash::make($password);
        }

        $user->name = $name;
        $user->role = User::ROLE_ADMIN;
        $user->save();

        return $user;
    }

    private function upsertRestaurant(User $owner, string $slug, string $name, string $description, string $address): Restaurant
    {
        $restaurant = Restaurant::query()->firstOrNew(['slug' => $slug]);

        if (! $restaurant->exists || ! $restaurant->uuid) {
            $restaurant->uuid = (string) Str::uuid();
        }

        $restaurant->user_id = $owner->id;
        $restaurant->name = $name;
        $restaurant->description = $description;
        $restaurant->address = $address;
        $restaurant->save();

        // Clean testing dataset for this restaurant only.
        $restaurant->dishes()->withTrashed()->get()->each(function (Dish $dish): void {
            $dish->assets()->delete();
            $dish->forceDelete();
        });

        $restaurant->ingredients()->delete();

        return $restaurant;
    }

    private function upsertDomain(Restaurant $restaurant, string $domain): void
    {
        if (! class_exists(RestaurantDomain::class) || ! Schema::hasTable('restaurant_domains')) {
            return;
        }

        RestaurantDomain::query()->updateOrCreate(
            ['domain' => strtolower(trim($domain))],
            [
                'restaurant_id' => $restaurant->id,
                'kind' => 'subdomain',
                'is_primary' => true,
                'verified_at' => now(),
            ]
        );
    }

    /**
     * @param array<int, array{name:string,name_ar:string,unit:string,stock:float|int,low:float|int}> $definitions
     * @return array<string, Ingredient>
     */
    private function seedRestaurantIngredients(Restaurant $restaurant, array $definitions): array
    {
        $map = [];

        foreach ($definitions as $definition) {
            $globalIngredient = $this->resolveGlobalIngredient(
                name: $definition['name'],
                nameAr: $definition['name_ar']
            );

            $ingredient = Ingredient::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'name' => $globalIngredient->name,
            ]);

            if (! $ingredient->exists) {
                $ingredient->uuid = (string) Str::uuid();
            }

            $ingredient->global_ingredient_id = $globalIngredient->id;
            $ingredient->name_ar = $globalIngredient->name_ar ?: $definition['name_ar'];
            $ingredient->storage_disk = 'public';
            $ingredient->file_path = null;
            $ingredient->source_file_name = null;
            $ingredient->file_size = null;
            $ingredient->mime_type = null;
            $ingredient->stock_unit = $definition['unit'];
            $ingredient->current_stock_quantity = $definition['stock'];
            $ingredient->low_stock_threshold = $definition['low'];
            $ingredient->is_active = true;
            $ingredient->save();

            $map[$this->normalizeKey($globalIngredient->name)] = $ingredient;
            $map[$this->normalizeKey($definition['name'])] = $ingredient;
        }

        return $map;
    }

    /**
     * @param array<int, array{
     *   name:string,
     *   name_ar:string,
     *   description:string,
     *   description_ar:string,
     *   category:string,
     *   category_ar:string,
     *   price:float,
     *   calories:int,
     *   image_url:string,
     *   recipe:array<int, array{ingredient:string,qty:float|int,unit:string}>
     * }> $dishes
     * @param array<string, Ingredient> $ingredientMap
     */
    private function seedRestaurantDishes(Restaurant $restaurant, array $ingredientMap, array $dishes): void
    {
        foreach ($dishes as $dishDefinition) {
            $dish = Dish::query()->firstOrNew([
                'restaurant_id' => $restaurant->id,
                'name' => $dishDefinition['name'],
            ]);

            if (! $dish->exists) {
                $dish->uuid = (string) Str::uuid();
            }

            $dish->name_ar = $dishDefinition['name_ar'];
            $dish->description = $dishDefinition['description'];
            $dish->description_ar = $dishDefinition['description_ar'];
            $dish->price = $dishDefinition['price'];
            $dish->calories = $dishDefinition['calories'];
            $dish->category = $dishDefinition['category'];
            $dish->category_ar = $dishDefinition['category_ar'];
            $dish->status = 'published';
            $dish->image_url = $dishDefinition['image_url'];
            $dish->save();

            $recipeRows = [];
            foreach ($dishDefinition['recipe'] as $index => $recipe) {
                $ingredientKey = $this->normalizeKey($recipe['ingredient']);
                $ingredient = $ingredientMap[$ingredientKey] ?? null;

                if (! $ingredient) {
                    throw new \RuntimeException(sprintf(
                        'Missing ingredient "%s" for dish "%s".',
                        $recipe['ingredient'],
                        $dishDefinition['name']
                    ));
                }

                $recipeRows[] = [
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $recipe['qty'],
                    'unit' => $recipe['unit'],
                    'order_index' => $index,
                    'show_in_animation' => true,
                ];
            }

            $dish->dishIngredients()->delete();
            $dish->dishIngredients()->createMany($recipeRows);
        }
    }

    private function resolveGlobalIngredient(string $name, ?string $nameAr = null): GlobalIngredient
    {
        $normalizedName = $this->normalizeKey($name);

        $globalIngredient = GlobalIngredient::query()
            ->where('normalized_name', $normalizedName)
            ->first();

        if ($globalIngredient) {
            if (! $globalIngredient->name_ar && $nameAr) {
                $globalIngredient->name_ar = $nameAr;
                $globalIngredient->save();
            }

            return $globalIngredient;
        }

        return GlobalIngredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => trim($name),
            'name_ar' => $nameAr,
            'normalized_name' => $normalizedName,
        ]);
    }

    private function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
