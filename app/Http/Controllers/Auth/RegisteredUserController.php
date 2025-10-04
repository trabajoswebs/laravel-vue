<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\SecurityHelper;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Mostrar la página de registro.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    /**
     * Manejar una solicitud de registro.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Validación principal - simple pero efectiva
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\p{L}\p{M}\s\-\'\.]+$/u',
            ],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'confirmed',
                Rules\Password::min(8)->letters()->numbers()->uncompromised()
            ],
        ], [
            // Mensajes de error usando traducciones de Laravel
            'name.required' => __('validation.required', ['attribute' => __('validation.attributes.name')]),
            'name.min' => __('validation.min.string', ['attribute' => __('validation.attributes.name'), 'min' => 2]),
            'name.max' => __('validation.max.string', ['attribute' => __('validation.attributes.name'), 'max' => 100]),
            'name.regex' => __('validation.regex', ['attribute' => __('validation.attributes.name')]),

            'email.required' => __('validation.required', ['attribute' => __('validation.attributes.email')]),
            'email.email' => __('validation.email', ['attribute' => __('validation.attributes.email')]),
            'email.unique' => __('validation.unique', ['attribute' => __('validation.attributes.email')]),

            'password.required' => __('validation.required', ['attribute' => __('validation.attributes.password')]),
            'password.confirmed' => __('validation.confirmed', ['attribute' => __('validation.attributes.password')]),
            'password.min' => __('validation.min.string', ['attribute' => __('validation.attributes.password'), 'min' => 8]),
        ]);

        try {
            // Sanitización final (los datos ya vienen sanitizados del middleware)
            $userData = $this->prepareUserData($validated);

            if (!$this->isDataSecure($userData)) {
                // Log del error sin exponer información sensible
                Log::error('Datos de usuario no seguros', [
                    'name' => $userData['name'],
                    'email_domain' => isset($validated['email']) ? $this->getEmailDomain($validated['email']) : null,
                    'timestamp' => now()->toISOString()
                ]);

                // Lanzar excepción de validación con mensaje amigable
                throw ValidationException::withMessages([
                    'email' => __('auth.registration_failed')
                ]);
            }

            // Crear usuario
            DB::transaction(function () use ($userData, &$user) {
                $user = User::create($userData); // rellena solo name,email,password
            });

            // Log de registro exitoso (sin datos sensibles)
            Log::info('Usuario registrado exitosamente', [
                'user_id' => $user->id,
                'email_domain' => $this->getEmailDomain($user->email),
                'timestamp' => now()->toISOString()
            ]);

            // Disparar evento y autenticar
            event(new Registered($user));

            Auth::login($user);

            return redirect()->route('dashboard')
                ->with('success', SecurityHelper::sanitizeOutput(__('auth.registration_successful'))); 
        } catch (\Exception $e) {
            // Log del error sin exponer información sensible
            Log::error('Error en registro de usuario', [
                'error_type' => get_class($e),
                'error_message' => $e->getMessage(),
                'email_domain' => isset($validated['email']) ? $this->getEmailDomain($validated['email']) : null,
                'timestamp' => now()->toISOString()
            ]);

            // Lanzar excepción de validación con mensaje amigable
            throw ValidationException::withMessages([
                'email' => __('auth.registration_failed')
            ]);
        }
    }

    /**
     * Preparar datos del usuario para la creación usando SecurityHelper
     * @param array $validated
     * @return array
    */
    // Prepare user data for creation using SecurityHelper
    private function prepareUserData(array $validated): array
    {
        return [
            'name' => SecurityHelper::sanitizeUserName($validated['name']),
            'email' => SecurityHelper::sanitizeEmail($validated['email']),
            'password' => Hash::make($validated['password']),
        ];
    }

    /**
     * @param array $userData
     * @return bool
     * Verificar que los datos sanitizados son seguros
     */
    private function isDataSecure(array $userData): bool
    {
        // Verificar que los datos no están vacíos después de la sanitización
        if (empty($userData['name']) || empty($userData['email'])) {
            return false;
        }

        // Verificar que no contienen contenido malicioso
        if (SecurityHelper::containsMaliciousContent($userData['name'])) {
            return false;
        }

        // Verificar longitudes
        if (strlen($userData['name']) > 100 || strlen($userData['email']) > 255) {
            return false;
        }

        return true;
    }

    /**
     * Obtener el dominio del email para logging (sin exponer el email completo)
     * @param string $email
     * @return string
     */
    private function getEmailDomain(string $email): string
    {
        $parts = explode('@', $email);
        return isset($parts[1]) ? $parts[1] : 'unknown';
    }
}
