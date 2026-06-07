<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveContactLeadRequest;
use App\Http\Requests\SendAiChatMessageRequest;
use App\Mail\NewContactLeadMail;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\ContactLead;
use App\Services\DeepSeekChatService;
use App\Support\RozerContactDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    public function __construct(
        private readonly DeepSeekChatService $deepSeekChatService,
        private readonly RozerContactDetector $contactDetector,
    ) {
    }

    public function createSession(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'source_page' => ['nullable', 'string', 'max:255'],
            ]);

            $session = ChatSession::create([
                'uuid' => (string) Str::uuid(),
                'status' => 'active',
                'source_page' => $this->cleanNullable($validated['source_page'] ?? null),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 65535, ''),
            ]);

            $welcomeMessage = "Hi, I'm Rozer, your AI contact assistant. I'm a bot, but I'll do my best to help you quickly. Are you looking for support, pricing, a demo, or general contact information?";

            $session->messages()->create([
                'role' => 'assistant',
                'content' => $welcomeMessage,
                'metadata' => ['type' => 'welcome'],
            ]);

            return $this->successResponse([
                'session_uuid' => $session->uuid,
                'message' => [
                    'role' => 'assistant',
                    'content' => $welcomeMessage,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create Rozer chat session.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse();
        }
    }

    public function sendMessage(SendAiChatMessageRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $session = ChatSession::query()->where('uuid', $validated['session_uuid'])->firstOrFail();
            $messageText = $this->cleanText($validated['message']);

            if (array_key_exists('source_page', $validated)) {
                $session->source_page = $this->cleanNullable($validated['source_page']);
                $session->save();
            }

            $session->messages()->create([
                'role' => 'user',
                'content' => $messageText,
            ]);

            $assistantReply = $this->deepSeekChatService->contactReply($this->buildConversationPayload($session));

            $session->messages()->create([
                'role' => 'assistant',
                'content' => $assistantReply,
            ]);

            $leadDetected = $this->detectAndPersistLead($session, $messageText);

            return $this->successResponse([
                'session_uuid' => $session->uuid,
                'message' => [
                    'role' => 'assistant',
                    'content' => $assistantReply,
                ],
                'lead_detected' => $leadDetected,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to send Rozer chat message.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse();
        }
    }

    public function saveLead(SaveContactLeadRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $session = ChatSession::query()->where('uuid', $validated['session_uuid'])->firstOrFail();

            $lead = DB::transaction(function () use ($session, $validated): ContactLead {
                $this->fillSessionWithLeadData($session, [
                    'name' => $validated['name'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'business_type' => $validated['business_type'] ?? null,
                    'preferred_contact_method' => $validated['preferred_contact_method'] ?? null,
                ]);

                $lead = $session->contactLead()->firstOrNew();
                $lead->fill([
                    'name' => $this->cleanNullable($validated['name'] ?? $session->visitor_name),
                    'email' => $this->cleanNullable($validated['email'] ?? $session->visitor_email),
                    'phone' => $this->cleanNullable($validated['phone'] ?? $session->visitor_phone),
                    'business_type' => $this->cleanNullable($validated['business_type'] ?? $session->business_type),
                    'preferred_contact_method' => $this->cleanNullable($validated['preferred_contact_method'] ?? $session->preferred_contact_method),
                    'message' => $this->cleanNullable($validated['message'] ?? null),
                    'conversation_summary' => $this->buildConversationSummary($session),
                    'source_page' => $session->source_page,
                    'status' => 'new',
                ]);
                $lead->chat_session_id = $session->id;
                $lead->save();

                return $lead;
            });

            $this->notifyLeadIfNeeded($lead, true);

            return $this->successResponse([
                'session_uuid' => $session->uuid,
                'lead_id' => $lead->id,
            ], 'Contact details saved successfully.');
        } catch (\Throwable $exception) {
            Log::error('Failed to save Rozer contact lead.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse();
        }
    }

    public function getSession(string $uuid): JsonResponse
    {
        try {
            $session = ChatSession::query()
                ->with([
                    'messages' => fn ($query) => $query
                        ->select(['id', 'chat_session_id', 'role', 'content', 'metadata', 'created_at'])
                        ->orderBy('id'),
                ])
                ->where('uuid', $uuid)
                ->firstOrFail();

            return $this->successResponse([
                'session' => [
                    'uuid' => $session->uuid,
                    'visitor_name' => $session->visitor_name,
                    'visitor_email' => $session->visitor_email,
                    'visitor_phone' => $session->visitor_phone,
                    'business_type' => $session->business_type,
                    'preferred_contact_method' => $session->preferred_contact_method,
                    'status' => $session->status,
                    'source_page' => $session->source_page,
                    'created_at' => $session->created_at?->toIso8601String(),
                ],
                'messages' => $session->messages->map(fn (ChatMessage $message): array => [
                    'role' => $message->role,
                    'content' => $message->content,
                    'metadata' => $message->metadata,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to fetch Rozer chat session.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse();
        }
    }

    private function buildConversationPayload(ChatSession $session): array
    {
        $messages = $session->messages()
            ->latest('id')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        $payload = [
            [
                'role' => 'system',
                'content' => DeepSeekChatService::ROZER_SYSTEM_PROMPT,
            ],
        ];

        foreach ($messages as $message) {
            $payload[] = [
                'role' => $message->role,
                'content' => $message->content,
            ];
        }

        return $payload;
    }

    private function detectAndPersistLead(ChatSession $session, string $messageText): bool
    {
        $detected = $this->contactDetector->detect($messageText);

        if ($detected === []) {
            return false;
        }

        $lead = DB::transaction(function () use ($session, $detected, $messageText): ContactLead {
            $this->fillSessionWithLeadData($session, $detected);

            $lead = $session->contactLead()->firstOrNew();
            $lead->fill([
                'name' => $this->cleanNullable($detected['name'] ?? $lead->name ?? $session->visitor_name),
                'email' => $this->cleanNullable($detected['email'] ?? $lead->email ?? $session->visitor_email),
                'phone' => $this->cleanNullable($detected['phone'] ?? $lead->phone ?? $session->visitor_phone),
                'business_type' => $this->cleanNullable($detected['business_type'] ?? $lead->business_type ?? $session->business_type),
                'preferred_contact_method' => $this->cleanNullable($detected['preferred_contact_method'] ?? $lead->preferred_contact_method ?? $session->preferred_contact_method),
                'message' => $messageText,
                'conversation_summary' => $this->buildConversationSummary($session),
                'source_page' => $session->source_page,
                'status' => $lead->exists ? $lead->status : 'new',
            ]);
            $lead->chat_session_id = $session->id;
            $lead->save();

            return $lead;
        });

        $this->notifyLeadIfNeeded($lead, false);

        return filled($lead->email) || filled($lead->phone);
    }

    private function fillSessionWithLeadData(ChatSession $session, array $data): void
    {
        $session->fill([
            'visitor_name' => $this->cleanNullable($data['name'] ?? $session->visitor_name),
            'visitor_email' => $this->cleanNullable($data['email'] ?? $session->visitor_email),
            'visitor_phone' => $this->cleanNullable($data['phone'] ?? $session->visitor_phone),
            'business_type' => $this->cleanNullable($data['business_type'] ?? $session->business_type),
            'preferred_contact_method' => $this->cleanNullable($data['preferred_contact_method'] ?? $session->preferred_contact_method),
        ]);
        $session->save();
    }

    private function notifyLeadIfNeeded(ContactLead $lead, bool $forceNotify): void
    {
        $recipient = (string) config('services.rozer.contact_email', '');

        $hasLeadDetails = filled($lead->name)
            || filled($lead->email)
            || filled($lead->phone)
            || filled($lead->business_type)
            || filled($lead->preferred_contact_method)
            || filled($lead->message)
            || filled($lead->conversation_summary);

        if ($recipient === '' || ! $hasLeadDetails) {
            return;
        }

        $shouldSend = $lead->status !== 'notified'
            || $lead->wasRecentlyCreated
            || $lead->wasChanged(['name', 'email', 'phone', 'business_type', 'preferred_contact_method', 'message']);

        if ($forceNotify) {
            $shouldSend = true;
        }

        if (! $shouldSend) {
            return;
        }

        Mail::to($recipient)->send(new NewContactLeadMail($lead));

        $lead->status = 'notified';
        $lead->save();
    }

    private function buildConversationSummary(ChatSession $session): string
    {
        return $session->messages()
            ->latest('id')
            ->limit(6)
            ->get(['role', 'content'])
            ->reverse()
            ->map(
                fn (ChatMessage $message): string => sprintf(
                    '%s: %s',
                    $message->role,
                    Str::limit($message->content, 240)
                )
            )
            ->implode("\n");
    }

    private function cleanText(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function cleanNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim(strip_tags($value));

        return $cleaned === '' ? null : $cleaned;
    }

    private function successResponse(array $data, string $message = 'Request completed successfully.'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function errorResponse(string $message = 'Something went wrong. Please try again.', int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
