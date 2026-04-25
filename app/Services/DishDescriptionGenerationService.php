<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DishDescriptionGenerationService
{
    /**
     * @param array{
     *   name:string,
     *   category:string,
     *   calories:int|null,
     *   recipe_ingredients:array<int,array{
     *     ingredient_name:string,
     *     quantity_required:float|int,
     *     unit:string,
     *     order_index:int
     *   }>,
     *   target_languages:array<int,string>,
     *   restaurant_name?:string|null
     * } $payload
     * @return array{description:string,description_ar:string|null}
     */
    public function generate(array $payload): array
    {
        $apiKey = (string) config('services.deepseek.key', '');
        if ($apiKey === '') {
            throw new RuntimeException('DeepSeek API key is not configured.');
        }

        $targetLanguages = $this->normalizeTargetLanguages($payload['target_languages'] ?? []);
        $wantsArabic = in_array('ar', $targetLanguages, true);

        $name = trim((string) ($payload['name'] ?? ''));
        $category = trim((string) ($payload['category'] ?? ''));
        $calories = $payload['calories'] ?? null;
        $restaurantName = trim((string) ($payload['restaurant_name'] ?? ''));
        $ingredients = $this->normalizeIngredients($payload['recipe_ingredients'] ?? []);

        $systemPrompt = $this->buildSystemPrompt($wantsArabic);
        $userPrompt = $this->buildUserPrompt(
            $name,
            $category,
            is_int($calories) ? $calories : null,
            $ingredients,
            $restaurantName,
            $wantsArabic
        );

        $responseText = $this->executeRequest($apiKey, [
            'model' => (string) config('services.deepseek.model', 'deepseek-chat'),
            'temperature' => 0.35,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
        ]);

        $decoded = $this->extractJsonObject($responseText);

        $description = trim((string) ($decoded['description'] ?? ''));
        $descriptionAr = $wantsArabic
            ? trim((string) ($decoded['description_ar'] ?? ''))
            : null;

        if ($description === '') {
            $description = $this->buildFallbackEnglishDescription($name, $category, $ingredients, is_int($calories) ? $calories : null);
        }

        if ($wantsArabic && $descriptionAr === '') {
            $descriptionAr = $this->buildFallbackArabicDescription($name, $category, $ingredients, is_int($calories) ? $calories : null);
        }

        return [
            'description' => $description,
            'description_ar' => $wantsArabic ? $descriptionAr : null,
        ];
    }

    /**
     * @param array<int,string> $targetLanguages
     * @return array<int,string>
     */
    private function normalizeTargetLanguages(array $targetLanguages): array
    {
        $normalized = [];
        foreach ($targetLanguages as $language) {
            $candidate = strtolower(trim((string) $language));
            if (! in_array($candidate, ['en', 'ar'], true)) {
                continue;
            }
            $normalized[] = $candidate;
        }

        if ($normalized === []) {
            return ['en'];
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int,array<string,mixed>> $ingredients
     * @return array<int,array{
     *   ingredient_name:string,
     *   quantity_required:float,
     *   unit:string,
     *   order_index:int
     * }>
     */
    private function normalizeIngredients(array $ingredients): array
    {
        $normalized = [];

        foreach ($ingredients as $index => $ingredient) {
            $ingredientName = trim((string) ($ingredient['ingredient_name'] ?? ''));
            if ($ingredientName === '') {
                continue;
            }

            $normalized[] = [
                'ingredient_name' => $ingredientName,
                'quantity_required' => round((float) ($ingredient['quantity_required'] ?? 0), 3),
                'unit' => trim((string) ($ingredient['unit'] ?? '')),
                'order_index' => (int) ($ingredient['order_index'] ?? $index),
            ];
        }

        usort(
            $normalized,
            fn (array $left, array $right): int => $left['order_index'] <=> $right['order_index']
        );

        return $normalized;
    }

    private function buildSystemPrompt(bool $wantsArabic): string
    {
        return implode("\n", [
            'You are a senior menu copywriter.',
            'Return ONLY valid JSON, with no markdown and no extra keys.',
            'JSON shape:',
            $wantsArabic
                ? '{"description":"...","description_ar":"..."}'
                : '{"description":"...","description_ar":null}',
            'Rules:',
            '- description: English, appetizing and concise, 1-2 short sentences, 18-45 words.',
            '- Mention flavor or texture, avoid hype and avoid dietary claims unless explicit in input.',
            '- Use ingredient names from the input when useful.',
            '- If description_ar is requested, write natural Modern Standard Arabic, similar length.',
        ]);
    }

    /**
     * @param array<int,array{
     *   ingredient_name:string,
     *   quantity_required:float,
     *   unit:string,
     *   order_index:int
     * }> $ingredients
     */
    private function buildUserPrompt(
        string $name,
        string $category,
        ?int $calories,
        array $ingredients,
        string $restaurantName,
        bool $wantsArabic
    ): string {
        $ingredientLines = [];
        foreach ($ingredients as $ingredient) {
            $ingredientLines[] = sprintf(
                '- %s: %s %s',
                $ingredient['ingredient_name'],
                rtrim(rtrim(number_format($ingredient['quantity_required'], 3, '.', ''), '0'), '.'),
                $ingredient['unit']
            );
        }

        $lines = [
            'Dish data:',
            '- Name: '.$name,
            '- Category: '.$category,
            '- Calories: '.($calories !== null ? (string) $calories : 'unknown'),
            '- Restaurant: '.($restaurantName !== '' ? $restaurantName : 'unknown'),
            '- Target Arabic description: '.($wantsArabic ? 'yes' : 'no'),
            'Ingredients:',
            ...($ingredientLines !== [] ? $ingredientLines : ['- Not provided']),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $requestPayload
     */
    private function executeRequest(string $apiKey, array $requestPayload): string
    {
        $baseUrl = rtrim((string) config('services.deepseek.base_url', 'https://api.deepseek.com'), '/');
        $timeout = max(1, (int) config('services.deepseek.timeout', 20));
        $connectTimeout = max(1, (int) config('services.deepseek.connect_timeout', 5));
        $retryTimes = max(0, (int) config('services.deepseek.retry_times', 2));
        $retrySleepMs = max(0, (int) config('services.deepseek.retry_sleep_ms', 250));

        try {
            $response = Http::baseUrl($baseUrl)
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retryTimes, $retrySleepMs)
                ->acceptJson()
                ->withToken($apiKey)
                ->post('/chat/completions', $requestPayload);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('Dish description generation call threw exception', [
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('DeepSeek API request failed.');
        }

        if ($response->failed()) {
            Log::channel('ai')->warning('Dish description generation call failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new RuntimeException('DeepSeek API request failed with status '.$response->status().'.');
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($content === '') {
            throw new RuntimeException('DeepSeek API returned an empty response.');
        }

        return $content;
    }

    /**
     * @return array{description?:string,description_ar?:string|null}
     */
    private function extractJsonObject(string $content): array
    {
        $candidate = trim($content);

        if (str_starts_with($candidate, '```')) {
            $candidate = preg_replace('/^```(?:json)?\s*/i', '', $candidate) ?? $candidate;
            $candidate = preg_replace('/\s*```$/', '', $candidate) ?? $candidate;
            $candidate = trim($candidate);
        }

        $decoded = $this->decodeJson($candidate);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $snippet = substr($content, $start, ($end - $start) + 1);
            $decoded = $this->decodeJson($snippet);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function decodeJson(string $candidate): mixed
    {
        try {
            return json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,array{
     *   ingredient_name:string,
     *   quantity_required:float,
     *   unit:string,
     *   order_index:int
     * }> $ingredients
     */
    private function buildFallbackEnglishDescription(
        string $name,
        string $category,
        array $ingredients,
        ?int $calories
    ): string {
        $ingredientNames = array_slice(array_map(
            fn (array $ingredient): string => $ingredient['ingredient_name'],
            $ingredients
        ), 0, 4);

        $ingredientText = $ingredientNames === []
            ? 'carefully selected ingredients'
            : implode(', ', $ingredientNames);

        $caloriesText = $calories !== null ? " at around {$calories} calories" : '';

        return sprintf(
            '%s is a %s prepared with %s, offering a balanced and satisfying flavor profile%s.',
            $name !== '' ? $name : 'This dish',
            $category !== '' ? strtolower($category) : 'signature dish',
            $ingredientText,
            $caloriesText
        );
    }

    /**
     * @param array<int,array{
     *   ingredient_name:string,
     *   quantity_required:float,
     *   unit:string,
     *   order_index:int
     * }> $ingredients
     */
    private function buildFallbackArabicDescription(
        string $name,
        string $category,
        array $ingredients,
        ?int $calories
    ): string {
        $ingredientNames = array_slice(array_map(
            fn (array $ingredient): string => $ingredient['ingredient_name'],
            $ingredients
        ), 0, 4);

        $ingredientText = $ingredientNames === []
            ? 'مكونات مختارة بعناية'
            : implode('، ', $ingredientNames);

        $caloriesText = $calories !== null ? " بحوالي {$calories} سعرة حرارية" : '';

        return sprintf(
            '%s طبق من فئة %s يُحضَّر باستخدام %s ليقدم نكهة متوازنة وممتعة%s.',
            $name !== '' ? $name : 'هذا الطبق',
            $category !== '' ? $category : 'مميزة',
            $ingredientText,
            $caloriesText
        );
    }
}
