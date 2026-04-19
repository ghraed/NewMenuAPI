<?php

namespace App\Http\Controllers;

use App\Services\DeepSeekChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ChatController extends Controller
{
    private const SESSION_KEY = 'chatbot.conversations';
    private const SESSION_LANGUAGE_KEY = 'chatbot.conversation_languages';
    private const MAX_MESSAGES = 20;

    public function __construct(
        private readonly DeepSeekChatService $deepSeekChatService
    ) {
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|string|max:120',
            'language' => 'nullable|string|max:20',
        ]);

        $conversationId = trim((string) ($validated['conversation_id'] ?? ''));
        if ($conversationId === '') {
            $conversationId = 'default';
        }

        $message = trim($validated['message']);
        $inputLanguage = isset($validated['language']) ? trim((string) $validated['language']) : null;

        $session = $request->session();
        $allConversations = $session->get(self::SESSION_KEY, []);
        $allLanguages = $session->get(self::SESSION_LANGUAGE_KEY, []);
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

        try {
            $assistant = $this->deepSeekChatService->chat($chatMessages, $resolvedLanguage);
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
}
