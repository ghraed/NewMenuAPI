<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use RuntimeException;

class DeepSeekChatService
{
    public const ROZER_SYSTEM_PROMPT = <<<'PROMPT'
You are Rozer, a friendly AI contact assistant for Rozer.

Always introduce yourself as Rozer and clearly mention that you are a bot.

Your job is to help visitors contact the Rozer team, answer simple questions about services, pricing, demos, support, and general inquiries.

Rozer is a smart digital restaurant system that can include QR menu, guest ordering, staff order management, chef kitchen screen, accounting, invoices, inventory ingredients, stock history, analytics, and modern customer experience features.

Be friendly, professional, short, and clear.

When the visitor seems interested, politely suggest that they leave their phone number and/or email so the Rozer team can contact them.

Never pressure the visitor.

If the visitor asks something you are not sure about, say that you can forward the request to the Rozer team.

Collect useful details when possible:
- name
- phone number
- email
- business type
- what they need
- preferred contact method

After receiving contact information, thank the visitor and tell them the Rozer team will contact them soon.

Do not invent exact prices unless pricing data is provided. Say that pricing depends on features, restaurant size, and setup needs.
PROMPT;

    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @param array{
     *   restaurant_id?:int,
     *   restaurant_name?:string,
     *   restaurant_slug?:string,
     *   table_id?:int,
     *   menu_items?:array<int,array{
     *     name:string,
     *     category:string,
     *     price:string,
     *     description:string,
     *     ingredients:array<int,string>
     *   }>
     * }|null $chatContext
     * @return array{reply:string, order_data?:array<string,mixed>}
     */
    public function chat(array $messages, ?string $language = null, ?array $chatContext = null): array
    {
        $apiKey = (string) config('services.deepseek.key', '');

        if ($apiKey === '') {
            throw new RuntimeException('DeepSeek API key is not configured.');
        }

        $systemPrompt = $this->buildSystemPrompt($language, $chatContext);

        $payload = [
            'model' => (string) config('services.deepseek.model', 'deepseek-chat'),
            'temperature' => 0.3,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                ...$messages,
            ],
        ];

        $cacheKey = 'deepseek:'.sha1(json_encode([
            'lang' => $language,
            'messages' => $messages,
            'context' => $chatContext,
        ], JSON_UNESCAPED_UNICODE));

        $cacheTtl = max(0, (int) config('services.deepseek.cache_ttl', 60));

        if ($cacheTtl > 0) {
            /** @var array{reply:string, order_data?:array<string,mixed>} $cachedResult */
            $cachedResult = Cache::remember($cacheKey, now()->addSeconds($cacheTtl), function () use ($apiKey, $payload): array {
                return $this->executeRequest($apiKey, $payload);
            });

            return $cachedResult;
        }

        return $this->executeRequest($apiKey, $payload);
    }

    /**
     * @param array<int, array{role:string, content:string}> $messages
     */
    public function contactReply(array $messages): string
    {
        $apiKey = (string) config('services.deepseek.key', '');

        if ($apiKey === '') {
            return $this->fallbackContactReply();
        }

        $payload = [
            'model' => (string) config('services.deepseek.model', 'deepseek-chat'),
            'temperature' => 0.5,
            'max_tokens' => 500,
            'messages' => $messages,
        ];

        try {
            $response = $this->executeContactRequest($apiKey, $payload);
            $content = trim((string) data_get($response, 'choices.0.message.content', ''));

            return $content !== '' ? $content : $this->fallbackContactReply();
        } catch (Throwable $e) {
            Log::channel('ai')->warning('Rozer contact chat failed.', [
                'message' => $e->getMessage(),
            ]);

            return $this->fallbackContactReply();
        }
    }

    /**
     * @param array{
     *   restaurant_name?:string,
     *   restaurant_slug?:string,
     *   table_id?:int,
     *   menu_items?:array<int,array{
     *     name:string,
     *     category:string,
     *     price:string,
     *     description:string,
     *     ingredients:array<int,string>
     *   }>
     * }|null $chatContext
     */
    private function buildSystemPrompt(?string $language = null, ?array $chatContext = null): string
    {
        $lang = is_string($language) && trim($language) !== '' ? trim($language) : 'auto';
        $restaurantName = trim((string) ($chatContext['restaurant_name'] ?? ''));
        $restaurantSlug = trim((string) ($chatContext['restaurant_slug'] ?? ''));
        $tableId = isset($chatContext['table_id']) ? (int) $chatContext['table_id'] : null;
        /** @var array<int,array{name:string,category:string,price:string,description:string,ingredients:array<int,string>}> $menuItems */
        $menuItems = is_array($chatContext['menu_items'] ?? null) ? $chatContext['menu_items'] : [];

        $restaurantScopeLines = [];
        $restaurantScopeLines[] = 'Restaurant context:';
        $restaurantScopeLines[] = '- Name: '.($restaurantName !== '' ? $restaurantName : 'Unknown');
        $restaurantScopeLines[] = '- Slug: '.($restaurantSlug !== '' ? $restaurantSlug : 'Unknown');
        if ($tableId !== null && $tableId > 0) {
            $restaurantScopeLines[] = '- Table: '.$tableId;
        }
        $restaurantScopeLines[] = '- You must only answer using this restaurant menu context.';
        $restaurantScopeLines[] = '- If something is not in this menu data, clearly say it is not available for this restaurant.';

        $menuLines = [
            'Menu data (published dishes only):',
        ];

        if ($menuItems === []) {
            $menuLines[] = '- No published dishes are currently available for this restaurant.';
        } else {
            foreach (array_slice($menuItems, 0, 120) as $item) {
                $name = trim((string) ($item['name'] ?? ''));
                $category = trim((string) ($item['category'] ?? 'Uncategorized'));
                $price = trim((string) ($item['price'] ?? '0.00'));
                $description = trim((string) ($item['description'] ?? ''));
                if (mb_strlen($description) > 180) {
                    $description = mb_substr($description, 0, 177).'...';
                }

                $ingredients = array_values(array_filter(
                    is_array($item['ingredients'] ?? null) ? $item['ingredients'] : [],
                    fn ($ingredient): bool => is_string($ingredient) && trim($ingredient) !== ''
                ));

                $ingredientText = $ingredients === []
                    ? 'unknown'
                    : implode(', ', array_slice($ingredients, 0, 12));

                $line = sprintf(
                    '- %s | category: %s | price: %s | ingredients: %s',
                    $name !== '' ? $name : 'Unnamed dish',
                    $category,
                    $price,
                    $ingredientText
                );

                if ($description !== '') {
                    $line .= ' | description: '.$description;
                }

                $menuLines[] = $line;
            }
        }

        return implode("\n", [
            'You are a restaurant assistant for dine-in guests.',
            'Primary goals:',
            '1) Answer dish questions clearly (taste, portion, preparation style, spice level).',
            '2) Explain ingredients and highlight common allergens when relevant.',
            '3) Suggest complete meals and upsell suitable drinks/sides naturally.',
            '4) Support Arabic, English, and French. Use language: '.$lang.'. If auto, infer from user message.',
            '5) Allergy safety: if the guest mentions an allergy, acknowledge it, avoid unsafe suggestions, and suggest safer alternatives.',
            '6) Before finalizing any order, explicitly confirm the items and quantities with the guest.',
            '7) Never invent dishes or prices that are not in the provided menu data.',
            ...$restaurantScopeLines,
            ...$menuLines,
            'Output rules:',
            '- Normal chat: concise natural-language answer.',
            '- If the guest confirms placing an order, include exactly one JSON object with this shape:',
            '{"action":"place_order","items":[{"name":"Burger","quantity":2}]}',
            '- Use action value exactly "place_order".',
            '- Do not include extra keys in that order JSON object.',
            '- If order is not confirmed yet, do not output order JSON.',
        ]);
    }

    /**
     * @return array{reply:string, order_data?:array<string,mixed>}
     */
    private function normalizeAssistantOutput(string $content): array
    {
        $jsonObjects = $this->extractJsonObjects($content);

        $reply = '';
        foreach ($jsonObjects as $jsonObject) {
            if (isset($jsonObject['reply']) && is_string($jsonObject['reply'])) {
                $reply = trim($jsonObject['reply']);
                break;
            }
        }

        if ($reply === '') {
            $reply = trim($this->stripCodeFences($content));
        }

        if ($reply === '') {
            $reply = 'Sorry, I could not generate a response.';
        }

        $orderData = $this->extractOrderData($jsonObjects);

        $result = [
            'reply' => $reply,
        ];

        if ($orderData !== null) {
            $result['order_data'] = $orderData;
        }

        return $result;
    }

    /**
     * Safe JSON extraction from mixed model text.
     * Supports:
     * - Pure JSON responses
     * - ```json fenced blocks
     * - Plain text with embedded JSON objects
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractJsonObjects(string $raw): array
    {
        $objects = [];
        $seen = [];

        $pushIfObject = function (mixed $decoded) use (&$objects, &$seen): void {
            if (! is_array($decoded) || array_is_list($decoded)) {
                return;
            }

            $fingerprint = md5(json_encode($decoded));
            if (isset($seen[$fingerprint])) {
                return;
            }

            $seen[$fingerprint] = true;
            $objects[] = $decoded;
        };

        $trimmed = trim($raw);
        if ($trimmed !== '') {
            $pushIfObject($this->decodeJson($trimmed));
        }

        if (preg_match_all('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $raw, $matches)) {
            foreach ($matches[1] as $candidate) {
                $pushIfObject($this->decodeJson(trim($candidate)));
            }
        }

        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] !== '{') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($j = $i; $j < $length; $j++) {
                $ch = $raw[$j];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }

                    if ($ch === '\\') {
                        $escaped = true;
                        continue;
                    }

                    if ($ch === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($ch === '"') {
                    $inString = true;
                    continue;
                }

                if ($ch === '{') {
                    $depth++;
                    continue;
                }

                if ($ch === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $candidate = substr($raw, $i, $j - $i + 1);
                        $pushIfObject($this->decodeJson($candidate));
                        break;
                    }
                }
            }
        }

        return $objects;
    }

    private function decodeJson(string $candidate): mixed
    {
        try {
            return json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string,mixed>> $jsonObjects
     * @return array{action:string,items:array<int,array{name:string,quantity:int}>}|null
     */
    private function extractOrderData(array $jsonObjects): ?array
    {
        foreach ($jsonObjects as $jsonObject) {
            $fromTopLevel = $this->normalizePlaceOrderPayload($jsonObject);
            if ($fromTopLevel !== null) {
                return $fromTopLevel;
            }

            if (isset($jsonObject['order_data']) && is_array($jsonObject['order_data'])) {
                $fromOrderData = $this->normalizePlaceOrderPayload($jsonObject['order_data']);
                if ($fromOrderData !== null) {
                    return $fromOrderData;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{action:string,items:array<int,array{name:string,quantity:int}>}|null
     */
    private function normalizePlaceOrderPayload(array $payload): ?array
    {
        $action = $payload['action'] ?? null;

        if (! is_string($action) || trim($action) !== 'place_order') {
            return null;
        }

        $items = $payload['items'] ?? null;
        if (! is_array($items)) {
            return null;
        }

        $normalizedItems = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $nameRaw = $item['name'] ?? null;
            if (! is_string($nameRaw)) {
                continue;
            }

            $name = trim($nameRaw);
            if ($name === '') {
                continue;
            }

            $quantityRaw = $item['quantity'] ?? null;
            $quantity = is_int($quantityRaw)
                ? $quantityRaw
                : (is_numeric($quantityRaw) ? (int) $quantityRaw : 0);

            if ($quantity <= 0) {
                continue;
            }

            $normalizedItems[] = [
                'name' => $name,
                'quantity' => $quantity,
            ];
        }

        if ($normalizedItems === []) {
            return null;
        }

        return [
            'action' => 'place_order',
            'items' => $normalizedItems,
        ];
    }

    private function stripCodeFences(string $value): string
    {
        $withoutFences = preg_replace('/```(?:json)?/i', '', $value);

        if (! is_string($withoutFences)) {
            return trim($value);
        }

        return trim($withoutFences);
    }

    private function fallbackContactReply(): string
    {
        return "Hi, I'm Rozer, your AI contact assistant bot. I can still help you leave your phone number or email, and the Rozer team will get back to you soon.";
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function executeContactRequest(string $apiKey, array $payload): array
    {
        $apiUrl = (string) config('services.deepseek.api_url', 'https://api.deepseek.com/chat/completions');
        $timeout = max(1, (int) config('services.deepseek.timeout', 20));
        $connectTimeout = max(1, (int) config('services.deepseek.connect_timeout', 5));
        $retryTimes = max(0, (int) config('services.deepseek.retry_times', 2));
        $retrySleepMs = max(0, (int) config('services.deepseek.retry_sleep_ms', 250));

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retryTimes, $retrySleepMs)
                ->acceptJson()
                ->withToken($apiKey)
                ->post($apiUrl, $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('DeepSeek contact API request failed.', previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('DeepSeek contact API request failed with status '.$response->status().'.');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{reply:string, order_data?:array<string,mixed>}
     */
    private function executeRequest(string $apiKey, array $payload): array
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
                ->post('/chat/completions', $payload);
        } catch (Throwable $e) {
            Log::channel('ai')->warning('DeepSeek call threw exception', [
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('DeepSeek API request failed.');
        }

        if ($response->failed()) {
            Log::channel('ai')->warning('DeepSeek call failed', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new RuntimeException('DeepSeek API request failed with status '.$response->status().'.');
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        if ($content === '') {
            Log::channel('ai')->warning('DeepSeek returned empty content', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            throw new RuntimeException('DeepSeek API returned an empty response.');
        }

        return $this->normalizeAssistantOutput($content);
    }
}
