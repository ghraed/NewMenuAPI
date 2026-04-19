<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepSeekChatService
{
    private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    /**
     * @param array<int, array{role:string, content:string}> $messages
     * @return array{reply:string, order_data?:array<string,mixed>}
     */
    public function chat(array $messages, ?string $language = null): array
    {
        $apiKey = (string) env('DEEPSEEK_API_KEY', '');

        if ($apiKey === '') {
            throw new RuntimeException('DeepSeek API key is not configured.');
        }

        $systemPrompt = $this->buildSystemPrompt($language);

        $payload = [
            'model' => 'deepseek-chat',
            'temperature' => 0.3,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                ...$messages,
            ],
        ];

        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($apiKey)
            ->post(self::ENDPOINT, $payload);

        if ($response->failed()) {
            throw new RuntimeException('DeepSeek API request failed with status '.$response->status().'.');
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        if ($content === '') {
            throw new RuntimeException('DeepSeek API returned an empty response.');
        }

        return $this->normalizeAssistantOutput($content);
    }

    private function buildSystemPrompt(?string $language = null): string
    {
        $lang = is_string($language) && trim($language) !== '' ? trim($language) : 'auto';

        return implode("\n", [
            'You are a restaurant assistant for dine-in guests.',
            'Primary goals:',
            '1) Answer dish questions clearly (taste, portion, preparation style, spice level).',
            '2) Explain ingredients and highlight common allergens when relevant.',
            '3) Suggest complete meals and upsell suitable drinks/sides naturally.',
            '4) Support Arabic, English, and French. Use language: '.$lang.'. If auto, infer from user message.',
            '5) Allergy safety: if the guest mentions an allergy, acknowledge it, avoid unsafe suggestions, and suggest safer alternatives.',
            '6) Before finalizing any order, explicitly confirm the items and quantities with the guest.',
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
}
