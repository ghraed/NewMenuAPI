<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\Restaurant;
use App\Services\GuestMenuSessionService;
use App\Services\DeepSeekChatService;
use App\Services\TenantRestaurantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ChatController extends Controller
{
    private const SESSION_KEY = 'chatbot.conversations';
    private const SESSION_LANGUAGE_KEY = 'chatbot.conversation_languages';
    private const MAX_MESSAGES = 20;
    private const MAX_CONVERSATIONS = 50;

    public function __construct(
        private readonly DeepSeekChatService $deepSeekChatService,
        private readonly GuestMenuSessionService $guestMenuSessionService,
        private readonly TenantRestaurantResolver $tenantRestaurantResolver
    ) {
    }

    public function chat(Request $request): JsonResponse
    {
        if (! $request->isJson()) {
            return response()->json([
                'message' => 'Invalid content type. Expected application/json.',
            ], 415);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|string|max:120',
            'language' => 'nullable|string|max:20',
            'restaurant_slug' => 'nullable|string|max:120',
            'table_id' => 'nullable|integer|min:1',
        ]);

        $chatRestaurant = $this->resolveChatRestaurant($validated, $request);
        if (! feature_enabled('ai_chatbot', $chatRestaurant)) {
            return response()->json([
                'message' => 'AI chatbot is disabled for this restaurant.',
            ], 403);
        }

        $conversationId = $this->sanitizeConversationId((string) ($validated['conversation_id'] ?? ''));

        $message = trim($validated['message']);
        if ($message === '') {
            return response()->json([
                'message' => 'Message cannot be empty.',
            ], 422);
        }

        $inputLanguage = isset($validated['language']) ? trim((string) $validated['language']) : null;

        $session = $request->session();
        $allConversations = $session->get(self::SESSION_KEY, []);
        $allLanguages = $session->get(self::SESSION_LANGUAGE_KEY, []);

        if (! is_array($allConversations)) {
            $allConversations = [];
        }

        if (! is_array($allLanguages)) {
            $allLanguages = [];
        }

        if (! array_key_exists($conversationId, $allConversations) && count($allConversations) >= self::MAX_CONVERSATIONS) {
            $oldestConversationId = array_key_first($allConversations);
            if (is_string($oldestConversationId) && $oldestConversationId !== '') {
                unset($allConversations[$oldestConversationId], $allLanguages[$oldestConversationId]);
            }
        }

        $history = data_get($allConversations, $conversationId, []);
        $storedLanguage = is_string(data_get($allLanguages, $conversationId))
            ? (string) data_get($allLanguages, $conversationId)
            : null;

        $resolvedLanguage = $this->normalizeLanguage($inputLanguage)
            ?? $this->normalizeLanguage($storedLanguage)
            ?? $this->detectLanguageFromMessage($message)
            ?? 'en';

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = [
            'id' => (string) Str::uuid(),
            'role' => 'user',
            'content' => $message,
            'at' => now()->toIso8601String(),
        ];

        $chatMessages = array_map(
            fn (array $entry): array => [
                'role' => (string) ($entry['role'] ?? 'user'),
                'content' => (string) ($entry['content'] ?? ''),
            ],
            array_slice($history, -self::MAX_MESSAGES)
        );

        $chatContext = $this->resolveChatContext($validated, $request);

        try {
            $assistant = $this->deepSeekChatService->chat($chatMessages, $resolvedLanguage, $chatContext);
            if (isset($assistant['reply']) && is_string($assistant['reply'])) {
                $assistant['reply'] = $this->appendNaturalRecommendation(
                    $assistant['reply'],
                    $message,
                    $chatContext
                );
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'reply' => 'Sorry, the assistant is temporarily unavailable.',
            ], 503);
        }

        $history[] = [
            'id' => (string) Str::uuid(),
            'role' => 'assistant',
            'content' => $assistant['reply'],
            'at' => now()->toIso8601String(),
        ];

        data_set($allConversations, $conversationId, array_slice($history, -self::MAX_MESSAGES));
        data_set($allLanguages, $conversationId, $resolvedLanguage);
        $session->put(self::SESSION_KEY, $allConversations);
        $session->put(self::SESSION_LANGUAGE_KEY, $allLanguages);

        $response = [
            'reply' => $assistant['reply'],
        ];

        if (isset($assistant['order_data']) && is_array($assistant['order_data'])) {
            $response['order_data'] = $assistant['order_data'];
        }

        return response()->json($response);
    }

    private function normalizeLanguage(?string $language): ?string
    {
        if (! is_string($language)) {
            return null;
        }

        $normalized = strtolower(trim($language));

        return match ($normalized) {
            'ar', 'arabic' => 'ar',
            'fr', 'french', 'francais', 'français' => 'fr',
            'en', 'english' => 'en',
            default => null,
        };
    }

    private function detectLanguageFromMessage(string $message): ?string
    {
        if ($message === '') {
            return null;
        }

        if (preg_match('/[\x{0600}-\x{06FF}]/u', $message) === 1) {
            return 'ar';
        }

        if (preg_match('/[àâçéèêëîïôûùüÿœæ]/iu', $message) === 1) {
            return 'fr';
        }

        if (preg_match('/\b(bonjour|bonsoir|merci|s(?:\'|’)il|je|voudrais|avec|sans|pour|menu|commande)\b/iu', $message) === 1) {
            return 'fr';
        }

        return 'en';
    }

    private function normalizeComparableText(string $value): string
    {
        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return is_string($normalized) ? $normalized : '';
    }

    private function sanitizeConversationId(string $conversationId): string
    {
        $normalized = trim($conversationId);

        if ($normalized === '') {
            return 'default';
        }

        $normalized = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $normalized);
        if (! is_string($normalized)) {
            return 'default';
        }

        $normalized = trim($normalized, '-_');
        if ($normalized === '') {
            return 'default';
        }

        return substr($normalized, 0, 120);
    }

    /**
     * @param array{
     *   restaurant_id?:int,
     *   restaurant_name?:string,
     *   restaurant_slug?:string,
     *   table_id?:int,
     *   menu_items?:array<int,array{
     *     id?:int,
     *     name:string,
     *     category:string,
     *     price:string,
     *     description:string,
     *     ingredients:array<int,string>,
     *     recommendation_priority?:string,
     *     related_items?:array<int,array{
     *       name:string,
     *       category:string,
     *       recommendation_priority?:string
     *     }>
     *   }>
     * } $chatContext
     */
    private function appendNaturalRecommendation(string $reply, string $message, array $chatContext): string
    {
        $trimmedReply = trim($reply);
        if ($trimmedReply === '') {
            return $reply;
        }

        $messageLower = Str::lower(trim($message));
        if ($messageLower === '') {
            return $reply;
        }

        $dishMentionRecommendation = $this->resolveMentionedDishRecommendation($messageLower, $chatContext);
        $directPreferredRecommendation = $this->resolveDirectPreferredRecommendation($messageLower, $chatContext);
        $categoryRecommendation = $this->resolveCategoryRecommendation($messageLower, $chatContext);
        $preferredCategoryRecommendation = $this->resolvePreferredCategoryDish($messageLower, $chatContext);
        $preferredKeywordRecommendation = $this->resolvePreferredKeywordDish($messageLower, $chatContext);
        $isRecommendationIntent = preg_match(
            '/\b(recommend|suggest|best|popular|top|pairing)\b|what should i order|what do you recommend|chef\'?s pick|رشح|اقترح|شو بتنصح|شو أطلب|شو الاقوى|recommande|suggestion/ui',
            $message
        ) === 1;

        if (
            $dishMentionRecommendation === null
            && $categoryRecommendation === null
            && $preferredCategoryRecommendation === null
            && $preferredKeywordRecommendation === null
            && $directPreferredRecommendation === null
            && ! $isRecommendationIntent
        ) {
            return $reply;
        }

        $candidate = $dishMentionRecommendation['dish']
            ?? $directPreferredRecommendation['dish']
            ?? $preferredKeywordRecommendation['dish']
            ?? $preferredCategoryRecommendation['dish']
            ?? $categoryRecommendation['dish']
            ?? $this->resolveGlobalPreferredDish($chatContext);
        if (! is_array($candidate) || trim((string) ($candidate['name'] ?? '')) === '') {
            return $reply;
        }

        $candidateName = trim((string) $candidate['name']);
        $trimmedReply = preg_replace('/\n\nIf you want (?:my honest pick|a solid place to start)[\s\S]*$/u', '', $trimmedReply) ?? $trimmedReply;
        $replyLower = Str::lower($trimmedReply);
        $candidateLower = Str::lower($candidateName);

        if (
            str_contains($replyLower, $candidateLower)
            && (
                str_contains($replyLower, 'honest pick')
                || str_contains($replyLower, 'start with')
                || str_contains($replyLower, 'go with')
                || str_contains($replyLower, 'safe choice')
            )
        ) {
            return $reply;
        }

        $secondary = $dishMentionRecommendation['secondary']
            ?? $directPreferredRecommendation['secondary']
            ?? $preferredKeywordRecommendation['secondary']
            ?? $preferredCategoryRecommendation['secondary']
            ?? $categoryRecommendation['secondary']
            ?? null;
        $categoryLabel = is_array($dishMentionRecommendation)
            ? trim((string) ($dishMentionRecommendation['category'] ?? ''))
            : (is_array($directPreferredRecommendation)
            ? trim((string) ($directPreferredRecommendation['category'] ?? ''))
            : (is_array($preferredKeywordRecommendation)
            ? trim((string) ($preferredKeywordRecommendation['category'] ?? ''))
            : (is_array($preferredCategoryRecommendation)
            ? trim((string) ($preferredCategoryRecommendation['category'] ?? ''))
            : (is_array($categoryRecommendation) ? trim((string) ($categoryRecommendation['category'] ?? '')) : ''))));

        if (
            str_contains($messageLower, 'pizza')
            && is_array($dishMentionRecommendation ?: $directPreferredRecommendation)
            && is_array(($dishMentionRecommendation ?: $directPreferredRecommendation)['dish'] ?? null)
        ) {
            $preferredSource = $dishMentionRecommendation ?: $directPreferredRecommendation;
            $preferredDish = $preferredSource['dish'];
            $preferredName = trim((string) ($preferredDish['name'] ?? ''));
            $preferredSecondary = is_array($preferredSource['secondary'] ?? null)
                ? trim((string) (($preferredSource['secondary']['name'] ?? '')))
                : '';

            if ($preferredName !== '') {
                if ($preferredSecondary !== '') {
                    return $trimmedReply."\n\nIf you want my honest pick from the pizza, I'd start with **{$preferredName}**. If you want a second option, **{$preferredSecondary}** is also a safe choice.";
                }

                return $trimmedReply."\n\nIf you want my honest pick from the pizza, I'd start with **{$preferredName}**.";
            }
        }

        if (is_array($secondary) && trim((string) ($secondary['name'] ?? '')) !== '') {
            $secondaryName = trim((string) $secondary['name']);

            if ($categoryLabel !== '') {
                return $trimmedReply."\n\nIf you want my honest pick from the {$categoryLabel}, I'd start with **{$candidateName}**. If you want a second option, **{$secondaryName}** is also a safe choice.";
            }

            return $trimmedReply."\n\nIf you want my honest pick, I'd start with **{$candidateName}**. **{$secondaryName}** is also a good option if you want a second choice.";
        }

        if ($categoryLabel !== '') {
            return $trimmedReply."\n\nIf you want my honest pick from the {$categoryLabel}, I'd start with **{$candidateName}**.";
        }

        return $trimmedReply."\n\nIf you want my honest pick, I'd start with **{$candidateName}**.";
    }

    /**
     * When a guest mentions a specific dish, prefer profitable related dishes first,
     * then profitable dishes in the same category, then any same-category fallback.
     *
     * @param array<string,mixed> $chatContext
     * @return array{category:string,dish:array<string,mixed>,secondary?:array<string,mixed>}|null
     */
    private function resolveMentionedDishRecommendation(string $messageLower, array $chatContext): ?array
    {
        $menuItems = is_array($chatContext['menu_items'] ?? null) ? $chatContext['menu_items'] : [];
        $mentionedDish = $this->resolveMentionedMenuItem($messageLower, $menuItems);
        if (! is_array($mentionedDish)) {
            return null;
        }

        $relatedItems = array_values(array_filter(
            is_array($mentionedDish['related_items'] ?? null) ? $mentionedDish['related_items'] : [],
            fn ($item): bool => is_array($item) && trim((string) ($item['name'] ?? '')) !== ''
        ));

        $sameCategoryItems = array_values(array_filter($menuItems, function (array $item) use ($mentionedDish): bool {
            return trim((string) ($item['category'] ?? '')) === trim((string) ($mentionedDish['category'] ?? ''))
                && trim((string) ($item['name'] ?? '')) !== trim((string) ($mentionedDish['name'] ?? ''));
        }));

        $preferredRelated = array_values(array_filter(
            $relatedItems,
            fn (array $item): bool => trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
        ));
        $preferredSameCategory = array_values(array_filter(
            $sameCategoryItems,
            fn (array $item): bool => trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
        ));

        $candidatePool = $preferredRelated !== []
            ? $preferredRelated
            : ($preferredSameCategory !== []
                ? $preferredSameCategory
                : ($relatedItems !== [] ? $relatedItems : $sameCategoryItems));

        $primary = $candidatePool[0] ?? null;
        if (! is_array($primary)) {
            return null;
        }

        $secondaryPool = array_values(array_filter($candidatePool, function (array $item) use ($primary): bool {
            return trim((string) ($item['name'] ?? '')) !== trim((string) ($primary['name'] ?? ''));
        }));

        if ($secondaryPool === []) {
            $fallbackSecondaryPool = $preferredSameCategory !== []
                ? $preferredSameCategory
                : ($relatedItems !== [] ? $relatedItems : $sameCategoryItems);

            $secondaryPool = array_values(array_filter($fallbackSecondaryPool, function (array $item) use ($primary): bool {
                return trim((string) ($item['name'] ?? '')) !== trim((string) ($primary['name'] ?? ''));
            }));
        }

        return [
            'category' => trim((string) ($mentionedDish['category'] ?? '')),
            'dish' => $primary,
            ...(($secondaryPool[0] ?? null) && is_array($secondaryPool[0]) ? ['secondary' => $secondaryPool[0]] : []),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $menuItems
     * @return array<string,mixed>|null
     */
    private function resolveMentionedMenuItem(string $messageLower, array $menuItems): ?array
    {
        $bestMatch = null;
        $bestLength = 0;

        foreach ($menuItems as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $normalizedName = $this->normalizeComparableText($name);
            if ($normalizedName === '' || ! str_contains($messageLower, $normalizedName)) {
                continue;
            }

            $nameLength = mb_strlen($normalizedName);
            if ($bestMatch === null || $nameLength > $bestLength) {
                $bestMatch = $item;
                $bestLength = $nameLength;
            }
        }

        return is_array($bestMatch) ? $bestMatch : null;
    }

    /**
     * @param array<string,mixed> $chatContext
     * @return array{category:string,dish:array<string,mixed>,secondary?:array<string,mixed>}|null
     */
    private function resolvePreferredCategoryDish(string $messageLower, array $chatContext): ?array
    {
        $menuItems = is_array($chatContext['menu_items'] ?? null) ? $chatContext['menu_items'] : [];
        $preferredItems = array_values(array_filter(
            $menuItems,
            fn (array $item): bool => trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
        ));

        foreach ($preferredItems as $item) {
            $category = Str::lower(trim((string) ($item['category'] ?? '')));
            if ($category === '') {
                continue;
            }

            $aliases = [$category];
            $parts = array_values(array_filter(explode(' ', $category)));
            if ($parts !== []) {
                $aliases[] = end($parts);
            }

            $matches = false;
            foreach ($aliases as $alias) {
                if ($alias !== '' && str_contains($messageLower, $alias)) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                continue;
            }

            $secondary = null;
            foreach ($menuItems as $candidate) {
                if (
                    trim((string) ($candidate['category'] ?? '')) === trim((string) ($item['category'] ?? ''))
                    && trim((string) ($candidate['name'] ?? '')) !== trim((string) ($item['name'] ?? ''))
                ) {
                    $secondary = $candidate;
                    break;
                }
            }

            return [
                'category' => (string) ($item['category'] ?? ''),
                'dish' => $item,
                ...($secondary && is_array($secondary) ? ['secondary' => $secondary] : []),
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $chatContext
     * @return array{category:string,dish:array<string,mixed>,secondary?:array<string,mixed>}|null
     */
    private function resolvePreferredKeywordDish(string $messageLower, array $chatContext): ?array
    {
        $menuItems = is_array($chatContext['menu_items'] ?? null) ? $chatContext['menu_items'] : [];
        $preferredItems = array_values(array_filter(
            $menuItems,
            fn (array $item): bool => trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
        ));

        foreach ($preferredItems as $item) {
            $category = trim((string) ($item['category'] ?? ''));
            if ($category === '') {
                continue;
            }

            $normalizedCategory = Str::lower(preg_replace('/\s+/', ' ', $category));
            if (! is_string($normalizedCategory) || $normalizedCategory === '') {
                continue;
            }

            $tokens = array_values(array_filter(explode(' ', $normalizedCategory)));
            $aliases = [$normalizedCategory];

            if ($tokens !== []) {
                $aliases[] = end($tokens);
            }

            foreach ($tokens as $token) {
                if (strlen($token) > 2) {
                    $aliases[] = $token;
                }
            }

            $aliases = array_values(array_unique(array_filter($aliases, fn ($alias): bool => is_string($alias) && $alias !== '')));

            $matches = false;
            foreach ($aliases as $alias) {
                if (str_contains($messageLower, $alias)) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                continue;
            }

            $secondary = null;
            foreach ($menuItems as $candidate) {
                if (
                    trim((string) ($candidate['category'] ?? '')) === $category
                    && trim((string) ($candidate['name'] ?? '')) !== trim((string) ($item['name'] ?? ''))
                ) {
                    $secondary = $candidate;
                    break;
                }
            }

            return [
                'category' => $category,
                'dish' => $item,
                ...($secondary && is_array($secondary) ? ['secondary' => $secondary] : []),
            ];
        }

        return null;
    }

    /**
     * Match profitable dishes directly against request keywords so a broad prompt like
     * "what pizza do you have?" still prefers the profitable pizza.
     *
     * @param array<string,mixed> $chatContext
     * @return array{category:string,dish:array<string,mixed>,secondary?:array<string,mixed>}|null
     */
    private function resolveDirectPreferredRecommendation(string $messageLower, array $chatContext): ?array
    {
        $menuItems = is_array($chatContext['menu_items'] ?? null) ? $chatContext['menu_items'] : [];
        $preferredItems = array_values(array_filter(
            $menuItems,
            fn (array $item): bool => trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
        ));

        $requestKeywords = array_values(array_unique(array_filter(
            preg_split('/\s+/', $messageLower) ?: [],
            fn ($token): bool => is_string($token) && strlen($token) >= 3
        )));

        foreach ($preferredItems as $item) {
            $category = trim((string) ($item['category'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($category === '' || $name === '') {
                continue;
            }

            $haystacks = [
                Str::lower($category),
                Str::lower($name),
            ];

            $matched = false;
            foreach ($requestKeywords as $keyword) {
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && str_contains($haystack, $keyword)) {
                        $matched = true;
                        break 2;
                    }
                }
            }

            if (! $matched) {
                continue;
            }

            $secondary = null;
            foreach ($menuItems as $candidate) {
                if (
                    trim((string) ($candidate['category'] ?? '')) === $category
                    && trim((string) ($candidate['name'] ?? '')) !== $name
                ) {
                    $secondary = $candidate;
                    break;
                }
            }

            return [
                'category' => $category,
                'dish' => $item,
                ...($secondary && is_array($secondary) ? ['secondary' => $secondary] : []),
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $chatContext
     * @return array{name:string,category:string,price:string,description:string,ingredients:array<int,string>,recommendation_priority?:string}|null
     */
    private function resolveGlobalPreferredDish(array $chatContext): ?array
    {
        $menuItems = is_array($chatContext['menu_items'] ?? null) ? $chatContext['menu_items'] : [];
        foreach ($menuItems as $item) {
            if (
                is_array($item)
                && trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
                && trim((string) ($item['name'] ?? '')) !== ''
            ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $chatContext
     * @return array{category:string,dish:array<string,mixed>,secondary?:array<string,mixed>}|null
     */
    private function resolveCategoryRecommendation(string $messageLower, array $chatContext): ?array
    {
        $menuItems = is_array($chatContext['menu_items'] ?? null) ? $chatContext['menu_items'] : [];
        $buckets = [];

        foreach ($menuItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $category = trim((string) ($item['category'] ?? ''));
            if ($category === '') {
                continue;
            }

            $key = Str::lower(preg_replace('/\s+/', ' ', $category));
            if (! is_string($key) || $key === '') {
                continue;
            }

            $aliases = [$key];
            if (str_ends_with($key, 'ies')) {
                $aliases[] = substr($key, 0, -3).'y';
            } elseif (str_ends_with($key, 'es')) {
                $aliases[] = substr($key, 0, -2);
            } elseif (str_ends_with($key, 's')) {
                $aliases[] = substr($key, 0, -1);
            }

            $parts = array_values(array_filter(explode(' ', $key)));
            if ($parts !== []) {
                $aliases[] = end($parts);
            }

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'category' => $category,
                    'aliases' => [],
                    'items' => [],
                ];
            }

            $buckets[$key]['aliases'] = array_values(array_unique(array_filter($aliases)));
            $buckets[$key]['items'][] = $item;
        }

        $bestBucket = null;
        foreach ($buckets as $bucket) {
            $matches = false;
            foreach ($bucket['aliases'] as $alias) {
                if ($alias !== '' && str_contains($messageLower, $alias)) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                continue;
            }

            $preferredCount = count(array_filter(
                $bucket['items'],
                fn (array $item): bool => trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
            ));

            if (
                $bestBucket === null
                || $preferredCount > $bestBucket['preferred_count']
                || ($preferredCount === $bestBucket['preferred_count'] && count($bucket['items']) > count($bestBucket['items']))
            ) {
                $bestBucket = [
                    'category' => $bucket['category'],
                    'items' => $bucket['items'],
                    'preferred_count' => $preferredCount,
                ];
            }
        }

        if ($bestBucket === null) {
            return null;
        }

        $preferredItems = array_values(array_filter(
            $bestBucket['items'],
            fn (array $item): bool => trim((string) ($item['recommendation_priority'] ?? '')) === 'preferred'
        ));

        $candidateItems = $preferredItems !== [] ? $preferredItems : $bestBucket['items'];
        $primary = $candidateItems[0] ?? null;
        if (! is_array($primary)) {
            return null;
        }

        $secondaryPool = array_values(array_filter(
            $bestBucket['items'],
            fn (array $item): bool => trim((string) ($item['name'] ?? '')) !== trim((string) ($primary['name'] ?? ''))
        ));

        return [
            'category' => (string) $bestBucket['category'],
            'dish' => $primary,
            ...(($secondaryPool[0] ?? null) && is_array($secondaryPool[0]) ? ['secondary' => $secondaryPool[0]] : []),
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{
     *   restaurant_id:int,
     *   restaurant_name:string,
     *   restaurant_slug:string,
     *   table_id?:int,
     *   menu_items:array<int,array{
     *     id:int,
     *     name:string,
     *     category:string,
     *     price:string,
     *     description:string,
     *     ingredients:array<int,string>,
     *     recommendation_priority?:string,
     *     related_items?:array<int,array{
     *       name:string,
     *       category:string,
     *       recommendation_priority?:string
     *     }>
     *   }>
     * }
     */
    private function resolveChatContext(array $validated, Request $request): array
    {
        $providedSlug = isset($validated['restaurant_slug']) ? trim((string) $validated['restaurant_slug']) : '';
        $tableId = isset($validated['table_id']) ? (int) $validated['table_id'] : null;

        $restaurant = null;
        $resolvedTableId = null;

        if ($tableId !== null) {
            try {
                $tableContext = $this->guestMenuSessionService->resolveTableContext($tableId);
            } catch (ModelNotFoundException) {
                // Ignore stale client table ids outside table-scoped guest flows.
                $tableContext = null;
            }

            if (is_array($tableContext)) {
                $restaurant = $tableContext['restaurant'] ?? null;
                $resolvedTableId = $tableId;
            }
        }

        if ($providedSlug !== '') {
            $restaurantBySlug = $this->tenantRestaurantResolver->resolveFromSlugOrHost($providedSlug, $request);

            if ($restaurant && $restaurant->id !== $restaurantBySlug->id) {
                abort(422, 'Chat restaurant slug does not match table context.');
            }

            $restaurant = $restaurantBySlug;
        }

        if (! $restaurant) {
            $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost(null, $request);
        }

        return [
            'restaurant_id' => (int) $restaurant->id,
            'restaurant_name' => (string) $restaurant->name,
            'restaurant_slug' => (string) $restaurant->slug,
            ...($resolvedTableId !== null ? ['table_id' => $resolvedTableId] : []),
            'menu_items' => $this->buildMenuItems((int) $restaurant->id),
        ];
    }

    /**
     * @param array<string, mixed> $validated
     */
    private function resolveChatRestaurant(array $validated, Request $request): Restaurant
    {
        $providedSlug = isset($validated['restaurant_slug']) ? trim((string) $validated['restaurant_slug']) : '';
        $tableId = isset($validated['table_id']) ? (int) $validated['table_id'] : null;

        $restaurant = null;

        if ($tableId !== null) {
            try {
                $tableContext = $this->guestMenuSessionService->resolveTableContext($tableId);
                $restaurant = $tableContext['restaurant'] ?? null;
            } catch (ModelNotFoundException) {
                $restaurant = null;
            }
        }

        if ($providedSlug !== '') {
            $restaurantBySlug = $this->tenantRestaurantResolver->resolveFromSlugOrHost($providedSlug, $request);

            if ($restaurant && $restaurant->id !== $restaurantBySlug->id) {
                abort(422, 'Chat restaurant slug does not match table context.');
            }

            $restaurant = $restaurantBySlug;
        }

        if (! $restaurant) {
            $restaurant = $this->tenantRestaurantResolver->resolveFromSlugOrHost(null, $request);
        }

        return $restaurant;
    }

    /**
     * @return array<int,array{
     *   id:int,
     *   name:string,
     *   category:string,
     *   price:string,
     *   description:string,
     *   ingredients:array<int,string>,
     *   recommendation_priority?:string,
     *   related_items?:array<int,array{
     *     name:string,
     *     category:string,
     *     recommendation_priority?:string
     *   }>
     * }>
     */
    private function buildMenuItems(int $restaurantId): array
    {
        return Dish::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', 'published')
            ->with([
                'dishIngredients.ingredient',
                'relatedDishes' => fn ($query) => $query
                    ->where('status', 'published')
                    ->select('dishes.id', 'dishes.name', 'dishes.category', 'dishes.is_profitable'),
                'suggestedDishes' => fn ($query) => $query
                    ->where('status', 'published')
                    ->select('dishes.id', 'dishes.name', 'dishes.category', 'dishes.is_profitable'),
            ])
            ->orderBy('category')
            ->orderBy('name')
            ->limit(200)
            ->get()
            ->map(function (Dish $dish): array {
                $ingredients = $dish->dishIngredients
                    ->map(fn ($dishIngredient): ?string => $dishIngredient->ingredient?->name)
                    ->filter(fn (?string $name): bool => is_string($name) && trim($name) !== '')
                    ->map(fn (string $name): string => trim($name))
                    ->unique()
                    ->values()
                    ->all();

                $relatedItems = collect()
                    ->concat($dish->relatedDishes)
                    ->concat($dish->suggestedDishes)
                    ->filter(fn ($candidate): bool => $candidate instanceof Dish && trim((string) $candidate->name) !== '')
                    ->map(fn (Dish $candidate): array => [
                        'name' => trim((string) $candidate->name),
                        'category' => trim((string) ($candidate->category ?? 'Uncategorized')),
                        'recommendation_priority' => $candidate->is_profitable ? 'preferred' : 'standard',
                    ])
                    ->unique(fn (array $candidate): string => Str::lower(trim((string) $candidate['name'])))
                    ->values()
                    ->all();

                return [
                    'id' => (int) $dish->id,
                    'name' => trim((string) $dish->name),
                    'category' => trim((string) ($dish->category ?? 'Uncategorized')),
                    'price' => number_format((float) $dish->price, 2, '.', ''),
                    'description' => trim((string) ($dish->description ?? '')),
                    'ingredients' => $ingredients,
                    'recommendation_priority' => $dish->is_profitable ? 'preferred' : 'standard',
                    'related_items' => $relatedItems,
                ];
            })
            ->values()
            ->all();
    }
}
