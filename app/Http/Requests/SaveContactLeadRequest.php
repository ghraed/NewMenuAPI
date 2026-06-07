<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveContactLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_uuid' => ['required', 'uuid', 'exists:chat_sessions,uuid'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:255'],
            'preferred_contact_method' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $email = trim((string) $this->input('email', ''));
            $phone = trim((string) $this->input('phone', ''));

            if ($email === '' && $phone === '') {
                $validator->errors()->add('contact', 'Please provide at least a phone number or an email address.');
            }
        });
    }
}
