<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAiChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_uuid' => ['required', 'uuid', 'exists:chat_sessions,uuid'],
            'message' => ['required', 'string', 'max:4000'],
            'source_page' => ['nullable', 'string', 'max:255'],
        ];
    }
}
