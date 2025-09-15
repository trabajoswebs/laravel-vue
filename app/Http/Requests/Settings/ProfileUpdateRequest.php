<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Helpers\SecurityHelper;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Reglas de validación.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
        ];
    }

    /**
     * Prepara los datos antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => SecurityHelper::sanitizeUserInput($this->name ?? ''),
        ]);
    }
}
