<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Helpers\SecurityHelper;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Solicitud de validación para la actualización parcial del perfil de un usuario.
 *
 * Esta clase gestiona la autorización, validación y saneado defensivo de los campos
 * 'name' y 'email' al actualizar el perfil. Soporta actualizaciones parciales mediante
 * la regla 'sometimes', y aplica saneado seguro antes de la validación para prevenir
 * inyecciones o formatos maliciosos.
 *
 * @see \App\Helpers\SecurityHelper Para las funciones de saneado utilizadas.
 */
class ProfileUpdateRequest extends FormRequest
{
    /**
     * Determina si el usuario autenticado está autorizado a actualizar el perfil solicitado.
     *
     * La autorización se concede únicamente si el usuario autenticado coincide con el
     * usuario especificado en la ruta (ya sea como modelo o ID). Esto previene que un
     * usuario modifique el perfil de otro.
     *
     * @return bool true si el usuario está autorizado, false en caso contrario.
     */
    public function authorize(): bool
    {
        $auth = $this->user();
        $routeUser = $this->route('user');

        if (!$auth || !$routeUser) {
            return false;
        }

        // Si el enlace de ruta ya resolvió un modelo de usuario (Route Model Binding)
        if ($routeUser instanceof Authenticatable) {
            return $auth->is($routeUser);
        }

        // Fallback: extraer el ID del usuario desde el parámetro de ruta (puede ser array, string o int)
        $routeUserId = (int) data_get($routeUser, 'id', $routeUser);
        return (int) $auth->getAuthIdentifier() === $routeUserId;
    }

    /**
     * Define las reglas de validación para los campos del perfil.
     *
     * Solo se validan los campos presentes en la solicitud ('sometimes').
     * El correo debe ser único (ignorando al usuario actual) y cumplir con estándares RFC/DNS.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     *     Array asociativo con las reglas de validación por campo.
     */
    public function rules(): array
    {
        /** @var \App\Models\User $user */
        $user = $this->user();

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'sometimes',
                'required',
                'string',
                'lowercase',                // Normaliza a minúsculas (disponible desde Laravel 10+)
                'email:rfc,dns',            // Valida formato RFC y resolución DNS
                'max:255',
                Rule::unique(User::class, 'email')->ignoreModel($user), // Ignora el propio correo del usuario
            ],
        ];
    }

    /**
     * Realiza saneado defensivo de los campos antes de la validación.
     *
     * Aplica funciones de saneado seguras desde SecurityHelper a los campos 'name' y 'email'.
     * En caso de fallo durante el saneado (p. ej., caracteres inválidos extremos),
     * se conserva el valor original para que las reglas de validación lo rechacen,
     * y se registra un evento de auditoría sin exponer datos sensibles.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $sanitized = [];

        if ($this->filled('name')) {
            try {
                // Normaliza espacios y caracteres permitidos en nombres
                $sanitized['name'] = SecurityHelper::sanitizeUserName((string) $this->input('name'));
            } catch (\InvalidArgumentException $e) {
                // Conservar el valor original para que falle en validación
                $sanitized['name'] = (string) $this->input('name');

                // Registrar intento sospechoso sin exponer el valor real
                Log::info('ProfileUpdateRequest: sanitizeUserName failed', [
                    'field'   => 'name',
                    'ip_hash' => SecurityHelper::hashIp($this->ip()),
                ]);
            }
        }

        if ($this->filled('email')) {
            try {
                // Normaliza y valida estructura básica del correo
                $sanitized['email'] = SecurityHelper::sanitizeEmail((string) $this->input('email'));
            } catch (\InvalidArgumentException $e) {
                $sanitized['email'] = (string) $this->input('email');

                // Nunca registrar correos en logs; solo metadatos seguros
                Log::info('ProfileUpdateRequest: sanitizeEmail failed', [
                    'field'   => 'email',
                    'ip_hash' => SecurityHelper::hashIp($this->ip()),
                ]);
            }
        }

        // Fusionar los valores saneados en la solicitud si hay cambios
        if ($sanitized !== []) {
            $this->merge($sanitized);
        }
    }

    /**
     * Personaliza los mensajes de error de validación.
     *
     * Utiliza claves de traducción para mantener la internacionalización.
     *
     * @return array<string, string> Array de mensajes personalizados por regla.
     */
    public function messages(): array
    {
        return [
            'name.required'   => __('validation.custom.name.required'),
            'name.max'        => __('validation.custom.name.max'),
            'email.required'  => __('validation.custom.email.required'),
            'email.lowercase' => __('validation.custom.email.lowercase'),
            'email.email'     => __('validation.custom.email.email'),
            'email.unique'    => __('validation.custom.email.unique'),
        ];
    }

    /**
     * Proporciona nombres legibles para los atributos en los mensajes de validación.
     *
     * Mejora la experiencia del usuario al mostrar "nombre" en lugar de "name".
     *
     * @return array<string, string> Array de nombres amigables por campo.
     */
    public function attributes(): array
    {
        return [
            'name'  => __('validation.attributes.name'),
            'email' => __('validation.attributes.email'),
        ];
    }
}