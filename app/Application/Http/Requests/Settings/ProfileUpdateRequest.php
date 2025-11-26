<?php

namespace App\Application\Http\Requests\Settings;

use App\Domain\Security\SecurityHelper;
use App\Domain\User\User;
use App\Domain\Sanitization\DisplayName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $sanitizedData = [];

        // Nombre: usamos filled() para ignorar campos vacíos
        if ($this->filled('name')) {
            $displayName = DisplayName::from($this->input('name'));

            if ($displayName->isValid()) {
                $sanitizedData['name'] = $displayName->sanitized();
            } else {
                Log::warning('ProfileUpdateRequest: display name rejected, enforcing validation failure', [
                    'field' => 'name',
                    'name_hash' => hash('sha256', $displayName->original()),
                    'ip_hash' => SecurityHelper::hashIp((string) $this->ip()),
                    'error' => $displayName->errorMessage(),
                ]);

                throw ValidationException::withMessages([
                    'name' => __('settings.profile.invalid_name_characters')
                ]);
            }
        }

        // Email: normalize + validate with helper
        if ($this->filled('email')) {
            try {
                // " TEST@MAIL.COM " => "test@mail.com"
                $sanitizedData['email'] = SecurityHelper::sanitizeEmail((string) $this->input('email'));
            } catch (\InvalidArgumentException $e) {
                $sanitizedData['email'] = (string) $this->input('email');

                Log::info(
                    'ProfileUpdateRequest: sanitizeEmail failed',
                    [
                        'field'   => $sanitizedData['email'],
                        'ip_hash' => SecurityHelper::hashIp(request()->ip()),
                    ]
                );
            }
        }

        if (!empty($sanitizedData)) {
            $this->merge($sanitizedData);
        }
    }

}
