<?php

namespace App\Http\Controllers;

use App\Models\GlobalIngredient;
use App\Models\Ingredient;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class IngredientLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $ingredients = $restaurant->ingredients()
            ->whereNotNull('file_path')
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $ingredient): array => $this->formatIngredient($ingredient))
            ->values();

        return response()->json($ingredients);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        $name = trim((string) $validated['name']);

        $ingredient = $restaurant->ingredients()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'name_ar' => $this->translateIngredientNameToArabic($name),
            'storage_disk' => 'public',
            'file_path' => null,
            'source_file_name' => null,
            'file_size' => null,
            'mime_type' => null,
        ]);

        return response()->json($this->formatIngredient($ingredient), 201);
    }

    public function update(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        if (array_key_exists('name', $validated)) {
            $name = trim((string) $validated['name']);
            if ($name !== '') {
                $ingredient->name = $name;
                $ingredient->name_ar = $ingredient->name_ar ?: $this->translateIngredientNameToArabic($name);
            }
        }

        $ingredient->save();

        return response()->json($this->formatIngredient($ingredient->fresh()));
    }

    public function destroy(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $this->deleteStoredIngredientFile($ingredient);
        $ingredient->delete();

        return response()->json([
            'message' => 'Ingredient deleted successfully.',
        ]);
    }

    public function bulkUpload(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => [
                'required',
                'file',
                'max:51200',
                function ($attribute, $value, $fail): void {
                    $ext = strtolower($value->getClientOriginalExtension());

                    if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true)) {
                        $fail('Ingredient library files must be images of type: jpg, jpeg, png, webp, heic, heif.');
                    }
                },
            ],
            'global_ingredient_ids' => ['sometimes', 'array'],
            'global_ingredient_ids.*' => ['nullable', 'integer', 'exists:global_ingredients,id'],
        ]);

        $uploaded = collect();

        /** @var UploadedFile[] $files */
        $files = $request->file('images', []);
        $requestedGlobalIngredientIds = $request->input('global_ingredient_ids', []);

        foreach ($files as $index => $file) {
            $requestedGlobalIngredientId = $this->normalizeOptionalInteger($requestedGlobalIngredientIds[$index] ?? null);
            $uploaded->push($this->storeIngredient($restaurant, $file, $requestedGlobalIngredientId));
        }

        return response()->json([
            'message' => 'Ingredient library updated successfully.',
            'uploaded_count' => $uploaded->count(),
            'ingredients' => $restaurant->ingredients()->whereNotNull('file_path')->orderBy('name')->get(),
        ], 201);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $ingredients = $restaurant->ingredients()->whereNotNull('file_path')->get();
        $deletedCount = $ingredients->count();

        foreach ($ingredients as $ingredient) {
            $this->deleteStoredIngredientFile($ingredient);

            $ingredient->update([
                'file_path' => null,
                'source_file_name' => null,
                'file_size' => null,
                'mime_type' => null,
            ]);
        }

        return response()->json([
            'message' => 'Ingredient library cleared successfully.',
            'deleted_count' => $deletedCount,
        ]);
    }

    public function generateImage(Request $request, Ingredient $ingredient): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $this->assertIngredientBelongsToRestaurant($ingredient, $restaurant);

        $updated = $this->generateAndStoreIngredientImage($ingredient);

        return response()->json($this->formatIngredient($updated));
    }

    public function generateMissingImages(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $missing = $restaurant->ingredients()
            ->where(function ($query) {
                $query->whereNull('file_path')->orWhere('file_path', '');
            })
            ->orderBy('id')
            ->get();

        $generatedCount = 0;
        $failed = [];

        foreach ($missing as $ingredient) {
            try {
                $this->generateAndStoreIngredientImage($ingredient);
                $generatedCount++;
            } catch (\Throwable $e) {
                report($e);
                $failed[] = [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Missing image generation completed.',
            'generated_count' => $generatedCount,
            'failed_count' => count($failed),
            'failed' => $failed,
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user?->loadMissing('restaurant');

        if (! $user?->restaurant) {
            abort(403, 'No restaurant is linked to this account.');
        }

        return $user->restaurant;
    }

    private function assertIngredientBelongsToRestaurant(Ingredient $ingredient, Restaurant $restaurant): void
    {
        if ((int) $ingredient->restaurant_id !== (int) $restaurant->id) {
            abort(404, 'Ingredient not found.');
        }
    }

    private function formatIngredient(Ingredient $ingredient): array
    {
        return [
            'id' => $ingredient->id,
            'uuid' => $ingredient->uuid,
            'name' => $ingredient->name,
            'name_ar' => $ingredient->name_ar,
            'category' => null,
            'global_ingredient_id' => $ingredient->global_ingredient_id,
            'file_url' => $ingredient->file_url,
            'image_url' => $ingredient->file_url,
            'image_status' => $ingredient->file_path ? 'exists' : 'missing',
            'source_file_name' => $ingredient->source_file_name,
            'file_size' => $ingredient->file_size,
            'mime_type' => $ingredient->mime_type,
            'created_at' => $ingredient->created_at?->toIso8601String(),
            'updated_at' => $ingredient->updated_at?->toIso8601String(),
        ];
    }

    private function generateAndStoreIngredientImage(Ingredient $ingredient): Ingredient
    {
        $apiKey = trim((string) env('OPENAI_API_KEY', ''));
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        $model = trim((string) env('OPENAI_IMAGE_MODEL', 'gpt-image-1'));
        $baseUrl = rtrim((string) env('OPENAI_API_BASE', 'https://api.openai.com/v1'), '/');

        $prompt = sprintf(
            'A centered, realistic studio product image of a single food ingredient: %s. Transparent background, no plate, no bowl, no utensils, no text, no watermark, PNG-ready cutout.',
            $ingredient->name
        );

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => '1024x1024',
            'background' => 'transparent',
        ];

        $requestClient = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(60);

        $response = $requestClient->post($baseUrl.'/images/generations', $payload);

        // Some OpenAI accounts/models reject `background`; retry once without it.
        if ($response->status() === 400) {
            $retryPayload = $payload;
            unset($retryPayload['background']);
            $response = $requestClient->post($baseUrl.'/images/generations', $retryPayload);
        }

        if ($response->failed()) {
            $errorMessage = (string) data_get($response->json(), 'error.message', '');
            $errorCode = (string) data_get($response->json(), 'error.code', '');
            $errorType = (string) data_get($response->json(), 'error.type', '');

            Log::warning('OpenAI image generation failed', [
                'ingredient_id' => $ingredient->id,
                'ingredient_name' => $ingredient->name,
                'status' => $response->status(),
                'error_type' => $errorType,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            $details = trim(implode(' | ', array_filter([
                $errorType !== '' ? "type={$errorType}" : null,
                $errorCode !== '' ? "code={$errorCode}" : null,
                $errorMessage !== '' ? $errorMessage : null,
            ])));

            throw new RuntimeException(
                'OpenAI image generation request failed with status '.$response->status()
                .($details !== '' ? " ({$details})" : '.')
            );
        }

        $b64 = data_get($response->json(), 'data.0.b64_json');
        $imageUrl = data_get($response->json(), 'data.0.url');
        $binary = null;

        if (is_string($b64) && trim($b64) !== '') {
            $decoded = base64_decode($b64, true);
            if ($decoded !== false && $decoded !== '') {
                $binary = $decoded;
            }
        }

        if (($binary === null || $binary === '') && is_string($imageUrl) && trim($imageUrl) !== '') {
            $imageResponse = Http::timeout(60)->get($imageUrl);
            if ($imageResponse->successful()) {
                $binary = $imageResponse->body();
            }
        }

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('OpenAI image generation returned empty image data.');
        }

        $this->deleteStoredIngredientFile($ingredient);

        $slug = Str::slug($ingredient->name) ?: 'ingredient-'.$ingredient->id;
        $fileName = $slug.'-'.now()->format('YmdHis').'.png';
        $path = "ingredients/{$ingredient->restaurant_id}/{$fileName}";
        Storage::disk('public')->put($path, $binary);

        $ingredient->update([
            'storage_disk' => 'public',
            'file_path' => $path,
            'source_file_name' => $fileName,
            'file_size' => strlen($binary),
            'mime_type' => 'image/png',
        ]);

        return $ingredient->fresh();
    }

    private function storeIngredient(Restaurant $restaurant, UploadedFile $file, ?int $requestedGlobalIngredientId = null): Ingredient
    {
        $derivedIngredientName = $this->ingredientNameFromFile($file->getClientOriginalName());
        $matchedGlobalIngredient = $this->resolveGlobalIngredientForUpload($derivedIngredientName, $requestedGlobalIngredientId);
        $ingredientName = $matchedGlobalIngredient?->name ?: $derivedIngredientName;

        $existingIngredient = null;

        if ($matchedGlobalIngredient) {
            $existingIngredient = $restaurant->ingredients()
                ->where('global_ingredient_id', $matchedGlobalIngredient->id)
                ->first();
        }

        if (! $existingIngredient) {
            $existingIngredient = $restaurant->ingredients()
                ->where('name', $ingredientName)
                ->first();
        }

        if ($existingIngredient) {
            $this->deleteStoredIngredientFile($existingIngredient);
        }

        $originalName = basename((string) $file->getClientOriginalName()) ?: 'ingredient.jpg';
        $path = $file->storeAs(
            "ingredients/{$restaurant->id}",
            Str::uuid().'-'.$originalName,
            'public'
        );

        $globalIngredientId = $matchedGlobalIngredient?->id ?: $existingIngredient?->global_ingredient_id;
        $nameArabic = $existingIngredient?->name_ar
            ?: $matchedGlobalIngredient?->name_ar
            ?: $this->translateIngredientNameToArabic($ingredientName);

        if ($existingIngredient) {
            $existingIngredient->update([
                'global_ingredient_id' => $globalIngredientId,
                'name' => $ingredientName,
                'name_ar' => $nameArabic,
                'storage_disk' => 'public',
                'file_path' => $path,
                'source_file_name' => $originalName,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType() ?: 'image/jpeg',
            ]);

            return $existingIngredient->fresh();
        }

        return $restaurant->ingredients()->create([
            'uuid' => (string) Str::uuid(),
            'global_ingredient_id' => $globalIngredientId,
            'name' => $ingredientName,
            'name_ar' => $nameArabic,
            'storage_disk' => 'public',
            'file_path' => $path,
            'source_file_name' => $originalName,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType() ?: 'image/jpeg',
        ]);
    }

    private function ingredientNameFromFile(string $fileName): string
    {
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $withSpaces = str_replace(['-', '_'], ' ', $baseName);

        return trim(preg_replace('/\s+/', ' ', $withSpaces) ?: $baseName);
    }

    private function resolveGlobalIngredientForUpload(string $ingredientName, ?int $requestedGlobalIngredientId): ?GlobalIngredient
    {
        if ($requestedGlobalIngredientId !== null) {
            $matchedById = GlobalIngredient::query()->find($requestedGlobalIngredientId);
            if ($matchedById) {
                return $matchedById;
            }
        }

        $normalizedName = $this->normalizeIngredientName($ingredientName);

        if ($normalizedName === '') {
            return null;
        }

        return GlobalIngredient::query()
            ->where('normalized_name', $normalizedName)
            ->first();
    }

    private function normalizeIngredientName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    private function normalizeOptionalInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $numericValue = (int) $value;

            return $numericValue > 0 ? $numericValue : null;
        }

        return null;
    }

    private function translateIngredientNameToArabic(string $ingredientName): string
    {
        $translations = [
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
            'red onion' => 'بصل أحمر',
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
            'burger' => 'برغر',
            'cheeseburger' => 'تشيز برغر',
            'lettuce' => 'خس',
            'tomato' => 'طماطم',
            'pickles' => 'مخلل',
            'burger sauce' => 'صلصة برغر',
            'swiss cheese' => 'جبنة سويسرية',
            'caramelized onions' => 'بصل مكرمل',
            'mayonnaise' => 'مايونيز',
            'jalapenos' => 'هالبينو',
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
            'onion' => 'بصل',
            'onions' => 'بصل',
            'green onion' => 'بصل أخضر',
            'green onions' => 'بصل أخضر',
            'provolone' => 'بروفولون',
            'tuna' => 'تونة',
            'fish' => 'سمك',
            'fettuccine' => 'فيتوتشيني',
            'cream' => 'كريمة',
            'garlic' => 'ثوم',
            'butter' => 'زبدة',
            'spaghetti' => 'سباغيتي',
            'ground beef' => 'لحم بقري مفروم',
            'penne' => 'بيني',
            'basil pesto' => 'بيستو ريحان',
            'cherry tomatoes' => 'طماطم كرزية',
            'shrimp' => 'روبيان',
            'chili flakes' => 'رقائق فلفل حار',
            'parsley' => 'بقدونس',
            'flat leaf parsley' => 'بقدونس',
            'romaine lettuce' => 'خس روماني',
            'croutons' => 'خبز محمص',
            'caesar dressing' => 'صلصة سيزر',
            'cucumber' => 'خيار',
            'feta' => 'فيتا',
            'mixed greens' => 'خضار ورقية مشكلة',
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
            'potato' => 'بطاطا',
            'potatoes' => 'بطاطا',
            'rice' => 'أرز',
            'rise' => 'أرز',
            'lentil' => 'عدس',
            'lentils' => 'عدس',
            'ketchup' => 'كاتشب',
            'cheese sauce' => 'صلصة جبنة',
            'coleslaw' => 'كولسلو',
            'cabbage' => 'ملفوف',
            'carrots' => 'جزر',
            'lava cake' => 'كيكة لافا',
            'chocolate' => 'شوكولاتة',
            'vanilla ice cream' => 'آيس كريم فانيلا',
            'cheesecake' => 'تشيزكيك',
            'cream cheese' => 'جبنة كريمية',
            'biscuits' => 'بسكويت',
            'tiramisu' => 'تيراميسو',
            'mascarpone' => 'ماسكاربوني',
            'coffee' => 'قهوة',
            'cocoa' => 'كاكاو',
            'brownie' => 'براوني',
            'hot fudge' => 'صلصة شوكولاتة ساخنة',
            'lemon' => 'ليمون',
            'lemon juice' => 'عصير ليمون',
            'mint' => 'نعناع',
            'mint leaves' => 'أوراق نعناع',
            'fresh mint leaves' => 'أوراق نعناع طازجة',
            'sugar syrup' => 'شراب سكر',
            'ice water' => 'ماء مثلج',
            'espresso' => 'إسبريسو',
            'milk' => 'حليب',
            'ice' => 'ثلج',
            'strawberries' => 'فراولة',
            'sugar' => 'سكر',
            'mango' => 'مانجو',
            'yogurt' => 'زبادي',
            'honey' => 'عسل',
        ];

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', str_replace('&', 'and', $ingredientName)) ?: $ingredientName));

        return $translations[$normalized] ?? $ingredientName;
    }

    private function deleteStoredIngredientFile(Ingredient $ingredient): void
    {
        if (! $ingredient->file_path) {
            return;
        }

        $disk = $ingredient->storage_disk ?: 'public';

        try {
            Storage::disk($disk)->delete($ingredient->file_path);
        } catch (\Throwable) {
            // Best-effort cleanup; keep the delete flow successful if the file is already gone.
        }
    }
}
