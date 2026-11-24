<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\PasswordPolicy;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => PasswordPolicy::rules(),
            // Optional promotion/event code (e.g. NLF) captured at signup. Not
            // validated against a known code — that happens server-side so a
            // wrong/unknown code degrades gracefully to the standard fee.
            'campaign_code' => ['nullable', 'string', 'max:40'],
            'referral_code' => ['nullable', 'string', 'max:32'],
            // Explicit sign-up consent for profile processing. AI drafting
            // consent is recorded separately when AI is first used.
            'privacy_consent' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, and one number or special character.',
        ];
    }
}
