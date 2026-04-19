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
            'You are a restaurant assistant for ordering help.',
            'Reply in language: '.$lang.'.',
            'You must return valid JSON only with this shape:',
            '{"reply":"string","order_data":{"items":[{"dish":"string","quantity":1}],"notes":"string"}}',
            'order_data is optional. Omit it when user is not placing or modifying an order intent.',
            'Do not include markdown fences.',
        ]);
    }

    /**
     * @return array{reply:string, order_data?:array<string,mixed>}
     */
    private function normalizeAssistantOutput(string $content): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded) && isset($decoded['reply']) && is_string($decoded['reply'])) {
            $result = [
                'reply' => trim($decoded['reply']),
            ];

            if (isset($decoded['order_data']) && is_array($decoded['order_data'])) {
                $result['order_data'] = $decoded['order_data'];
            }

            if ($result['reply'] === '') {
                $result['reply'] = 'Sorry, I could not generate a response.';
            }

            return $result;
        }

        return [
            'reply' => trim($content) !== ''
                ? trim($content)
                : 'Sorry, I could not generate a response.',
        ];
    }
}
