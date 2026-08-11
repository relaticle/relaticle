<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\TurnstileChallenge;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ContactRequest extends FormRequest
{
    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
            'cf-turnstile-response' => TurnstileChallenge::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cf-turnstile-response.required' => __('auth.turnstile.required'),
        ];
    }
}
