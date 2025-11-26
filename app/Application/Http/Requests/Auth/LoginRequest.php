<?php

namespace App\Application\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Application\Http\Requests\Concerns\SanitizesInputs;
use App\Domain\Security\RateLimitSignatureFactory;

/**
 * FormRequest para manejo de autenticación de usuarios
 *
 * Esta clase encapsula la lógica de validación, sanitización y autenticación
 * para el proceso de inicio de sesión. Proporciona una capa de seguridad
 * adicional con control de intentos fallidos (rate limiting) y logging
 * detallado de intentos de autenticación.
 *
 * Características principales:
 * - Validación estricta de credenciales de login
 * - Sanitización segura del email antes de la autenticación
 * - Control de intentos fallidos con rate limiting
 * - Logging de seguridad para auditoría de intentos
 * - Manejo de sesión "recordar usuario"
 * - Protección contra ataques de fuerza bruta
 *
 * El proceso de autenticación incluye:
 * 1. Validación de formato de email y contraseña
 * 2. Sanitización del email para prevenir inyecciones
 * 3. Control de intentos fallidos
 * 4. Intento de autenticación con Laravel Auth
 * 5. Logging de resultados para monitoreo de seguridad
 */
class LoginRequest extends FormRequest
{
    use SanitizesInputs;

    /**
     * Número máximo de intentos fallidos permitidos antes de bloqueo temporal.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Duración del bloqueo temporal en minutos después de exceder intentos.
     */
    private const DECAY_MINUTES = 15;

    /**
     * Longitud máxima permitida para la contraseña.
     */
    private const PASSWORD_MAX = 255;

    /**
     * Longitud mínima requerida para la contraseña.
     */
    private const PASSWORD_MIN = 8;

    /**
     * Determina si el usuario está autorizado a realizar esta solicitud.
     *
     * En este caso, se permite a cualquier usuario intentar iniciar sesión,
     * ya que la autorización se verifica durante el proceso de autenticación
     * real más adelante.
     *
     * @return bool Siempre true, ya que el acceso al endpoint de login es público
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepara los datos de entrada antes de la validación.
     *
     * Este método se ejecuta antes de la validación y permite:
     * - Sanitizar el email para prevenir inyecciones
     * - Normalizar el formato del email (minúsculas, sin espacios)
     * - Asegurar que el password no tenga espacios innecesarios
     * - Establecer un valor predeterminado para 'remember' si no está presente
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Sanitización y normalización del email
        if ($this->filled('email')) {
            $emailInput = (string) $this->input('email');

            try {
                // Aplica sanitización específica para emails
                $sanitizedEmail = $this->sanitizeFieldValue($emailInput, 'sanitizeEmail', 'login.email');
                // Convierte a minúsculas y elimina espacios para consistencia
                $this->merge(['email' => strtolower(trim($sanitizedEmail))]);
            } catch (\Throwable $exception) {
                // En caso de fallo en sanitización, registra el incidente y procede con el valor original
                // Esto permite que la validación posterior maneje el error de forma adecuada
                Log::warning('Email sanitization failed during login preparation, deferring to validation', [
                    'email_hash' => hash('sha256', $emailInput), // Hash para privacidad
                    'ip_hash' => hash('sha256', (string) $this->ip()),
                    'exception' => $exception->getMessage(),
                ]);
                $this->merge(['email' => $emailInput]);
            }
        }

        // Normalización del password (elimina espacios innecesarios)
        if ($this->filled('password')) {
            $this->merge(['password' => trim((string) $this->input('password'))]);
        }

        // Establece valor predeterminado para 'remember' si no está presente
        if (!$this->has('remember')) {
            $this->merge(['remember' => false]);
        }
    }

    /**
     * Define las reglas de validación para los campos de la solicitud.
     *
     * Estas reglas garantizan que:
     * - El email sea obligatorio, string, formato válido y no exceda 255 caracteres
     * - La contraseña sea obligatoria, string, y cumpla con los límites de longitud
     * - El campo 'remember' sea booleano (true/false)
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     *         Array de reglas de validación para cada campo
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:' . self::PASSWORD_MIN, 'max:' . self::PASSWORD_MAX],
            'remember' => ['required', 'boolean'],
        ];
    }

    /**
     * Intenta autenticar las credenciales del usuario.
     *
     * Este método realiza el proceso de autenticación completo:
     * 1. Verifica que no esté rate limited
     * 2. Registra el intento en el rate limiter
     * 3. Extrae las credenciales validadas
     * 4. Intenta la autenticación con Laravel Auth
     * 5. Registra el resultado para auditoría
     * 6. Lanza excepción si la autenticación falla
     *
     * @throws \Illuminate\Validation\ValidationException
     *         Si la autenticación falla o está rate limited
     * @return void
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $throttleKey = app(RateLimitSignatureFactory::class)->forLogin($this);
        $decayMinutes = (int) config('security.rate_limiting.login_decay_minutes', self::DECAY_MINUTES);
        RateLimiter::hit($throttleKey, $decayMinutes * 60);

        // Extrae credenciales validadas
        $data = $this->validated();
        $credentials = [
            'email' => (string) ($data['email'] ?? ''),
            'password' => (string) ($data['password'] ?? ''),
        ];
        $remember = (bool) ($data['remember'] ?? false);

        // Intenta autenticación
        $success = Auth::attempt($credentials, $remember);

        // Registra intento para auditoría de seguridad
        Log::info('Login attempt recorded', [
            'email_hash' => hash('sha256', $credentials['email']), // Hash para privacidad
            'ip_hash' => hash('sha256', (string) $this->ip()),
            'success' => $success,
            'throttle_key' => $throttleKey,
        ]);

        if (! $success) {
            // Si falla, lanza excepción con mensaje genérico
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'), // Mensaje genérico para seguridad
            ]);
        }

        // Si tiene éxito, limpia el contador de intentos fallidos
        RateLimiter::clear($throttleKey);
    }

    /**
     * Verifica que la solicitud de login no esté rate limited.
     *
     * Este método implementa la protección contra ataques de fuerza bruta
     * verificando si se han excedido los intentos permitidos en el período
     * definido. Si está rate limited, lanza una excepción con mensaje apropiado.
     *
     * @throws \Illuminate\Validation\ValidationException
     *         Si se excedieron los intentos permitidos
     * @return void
     */
    public function ensureIsNotRateLimited(): void
    {
        $maxAttempts = (int) config('security.rate_limiting.login_max_attempts', self::MAX_ATTEMPTS);
        $key = app(RateLimitSignatureFactory::class)->forLogin($this);
        if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return;
        }

        // Dispara evento de lockout para notificación y auditoría
        event(new Lockout($this));

        // Calcula tiempo restante de bloqueo
        $seconds = RateLimiter::availableIn($key);

        // Lanza excepción con mensaje de bloqueo
        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Genera la clave de rate limiting para la solicitud actual.
     *
     * La clave se basa en la combinación de email e IP para prevenir
     * ataques tanto por cuenta como por origen. Usa hash SHA256 para
     * proteger la privacidad de la información sensible.
     *
     * @return string Clave única para el rate limiting basada en email e IP
     */
    public function throttleKey(): string
    {
        return app(RateLimitSignatureFactory::class)->forLogin($this);
    }
}
