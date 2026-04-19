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
        $language = isset($validated['language']) ? trim((string) $validated['language']) : null;

        $session = $request->session();
        $allConversations = $session->get(self::SESSION_KEY, []);
        $history = data_get($allConversations, $conversationId, []);

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
            $assistant = $this->deepSeekChatService->chat($chatMessages, $language);
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
        $session->put(self::SESSION_KEY, $allConversations);

        $response = [
            'reply' => $assistant['reply'],
        ];

        if (isset($assistant['order_data']) && is_array($assistant['order_data'])) {
            $response['order_data'] = $assistant['order_data'];
        }

        return response()->json($response);
    }
}
