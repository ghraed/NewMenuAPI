<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ContactLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SuperAdminContactLeadController extends Controller
{
    public function index(): JsonResponse
    {
        $leads = ContactLead::query()
            ->with(['chatSession:id,uuid,visitor_name,visitor_email,visitor_phone'])
            ->latest('created_at')
            ->get();

        return response()->json([
            'requests' => $leads->map(fn (ContactLead $lead): array => [
                'id' => $lead->id,
                'title' => $this->buildLeadTitle($lead),
                'name' => $lead->name ?: $lead->chatSession?->visitor_name,
                'email' => $lead->email ?: $lead->chatSession?->visitor_email,
                'phone' => $lead->phone ?: $lead->chatSession?->visitor_phone,
                'preferred_contact_method' => $lead->preferred_contact_method,
                'business_type' => $lead->business_type,
                'status' => $lead->status,
                'source_page' => $lead->source_page,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function show(ContactLead $contactLead): JsonResponse
    {
        $contactLead->load([
            'chatSession.messages' => fn ($query) => $query
                ->select(['id', 'chat_session_id', 'role', 'content', 'created_at'])
                ->orderBy('id'),
        ]);

        return response()->json([
            'request' => [
                'id' => $contactLead->id,
                'title' => $this->buildLeadTitle($contactLead),
                'name' => $contactLead->name ?: $contactLead->chatSession?->visitor_name,
                'email' => $contactLead->email ?: $contactLead->chatSession?->visitor_email,
                'phone' => $contactLead->phone ?: $contactLead->chatSession?->visitor_phone,
                'business_type' => $contactLead->business_type,
                'preferred_contact_method' => $contactLead->preferred_contact_method,
                'message' => $contactLead->message,
                'conversation_summary' => $contactLead->conversation_summary,
                'source_page' => $contactLead->source_page,
                'status' => $contactLead->status,
                'created_at' => $contactLead->created_at?->toIso8601String(),
                'session_uuid' => $contactLead->chatSession?->uuid,
                'messages' => $contactLead->chatSession?->messages?->map(fn (ChatMessage $message): array => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values() ?? [],
            ],
        ]);
    }

    private function buildLeadTitle(ContactLead $lead): string
    {
        $base = trim((string) ($lead->message ?: ''));

        if ($base !== '') {
            return Str::limit($base, 72);
        }

        $summary = trim((string) ($lead->conversation_summary ?: ''));
        if ($summary !== '') {
            return Str::limit($summary, 72);
        }

        $name = trim((string) ($lead->name ?: $lead->chatSession?->visitor_name ?: ''));
        if ($name !== '') {
            return 'Request from '.$name;
        }

        return 'Visitor contact request';
    }
}
