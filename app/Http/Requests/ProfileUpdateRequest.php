<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Locales;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // A stolen session must not be able to move the account to another address.
            'current_password' => [Rule::excludeIf(! $this->changesEmail()), 'required', 'string', 'current_password'],
            // Empty follows the site default.
            'locale' => ['nullable', 'string', Rule::in(array_keys(Locales::available()))],
        ];
    }

    private function changesEmail(): bool
    {
        return (string) $this->input('email') !== $this->user()->email;
    }
}
