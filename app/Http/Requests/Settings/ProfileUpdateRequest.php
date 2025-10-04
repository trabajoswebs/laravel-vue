<?php

namespace App\Http\Requests\Settings;

use App\Helpers\SecurityHelper;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
            try {
                // "  Juan  Pérez " => "Juan Pérez"
                $sanitizedData['name'] = SecurityHelper::sanitizeUserName((string) $this->input('name'));
            } catch (\InvalidArgumentException $e) {
                // Dejar el valor crudo para que la validación lo capture
                $sanitizedData['name'] = (string) $this->input('name');

                // Log seguro: no guardamos IP en claro, usamos hash
                \Illuminate\Support\Facades\Log::info(
                    'ProfileUpdateRequest: sanitizeUserName failed',
                    [
                        'field'   => 'name',
                        'ip_hash' => SecurityHelper::hashIp(request()->ip()),
                    ]
                );
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
