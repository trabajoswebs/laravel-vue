<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Helpers\SecurityHelper;
use Illuminate\Support\Facades\Log;

class SanitizeInput
{
    // SECURITY FIX: Whitelist de métodos permitidos para mayor seguridad
    private const ALLOWED_METHODS = [
        'sanitizeUserName',
        'sanitizeUserInput',
        'sanitizeHtml',
        'sanitizeEmail',
        'sanitizeLocale'
    ];

    // SECURITY FIX: Configuración de campos a sanitizar
    private const FIELD_SANITIZATION_MAP = [
        'name' => 'sanitizeUserName',
        'description' => 'sanitizeUserInput',
        'content' => 'sanitizeHtml',
        'message' => 'sanitizeUserInput',
        'comment' => 'sanitizeUserInput',
        'title' => 'sanitizeUserInput',
        'bio' => 'sanitizeUserInput',
    ];

    public function handle(Request $request, Closure $next)
    {
        $this->sanitizeRequestFields($request);
        $this->sanitizeRouteParameters($request);

        return $next($request);
    }
    /**
     * SECURITY FIX: Sanitiza campos críticos de la solicitud
     * @param Request $request
     * @return void
     */
    private function sanitizeRequestFields(Request $request): void
    {
        foreach (self::FIELD_SANITIZATION_MAP as $field => $method) {
            if ($request->has($field)) {
                $this->sanitizeField($request, $field, $method);
            }
        }

        // SECURITY FIX:    Campos especiales con manejo específico
        $this->sanitizeEmailField($request);
        $this->sanitizeLocaleField($request);
    }
    /**
     * SECURITY FIX: Sanitiza campo
     * @param Request $request
     * @param string $field
     * @param string $method
     * @return void
     */
    private function sanitizeField(Request $request, string $field, string $method): void
    {
        $original = $request->input($field);

        try {
            $sanitizedValue = $this->applySanitization($original, $method);
            $request->merge([$field => $sanitizedValue]);
        } catch (\Throwable $e) {
            Log::warning('Field sanitization failed', [
                'field' => $field,
                'method' => $method,
                'user_id' => $request->user()?->id,
                'ip_hash' => substr(hash('sha256', $request->ip()), 0, 8),
                'error' => $e->getMessage(),
            ]);

            // Fallback seguro
            $this->applyFallbackSanitization($request, $field, $original);
        }
    }
    /**
     * SECURITY FIX: Aplica sanitización
     * @param mixed $value
     * @param string $method
     * @return mixed
     */
    private function applySanitization($value, string $method)
    {
        // Verificación de seguridad: método debe estar en whitelist
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new \InvalidArgumentException("Sanitization method '{$method}' not allowed");
        }

        if (!method_exists(SecurityHelper::class, $method)) {
            throw new \RuntimeException("Sanitization method '{$method}' not found");
        }

        if (is_array($value)) {
            return array_map(fn($item) => $this->applySanitization($item, $method), $value);
        }

        // Llamada segura al método estático
        return call_user_func([SecurityHelper::class, $method], $value);
    }

    /**
     * SECURITY FIX: Aplica sanitización de fallback
     * @param Request $request
     * @param string $field
     * @param mixed $original
     * @return void
     */
    private function applyFallbackSanitization(Request $request, string $field, $original): void
    {
        try {
            $fallbackValue = SecurityHelper::sanitizeUserInput($original);
            $request->merge([$field => $fallbackValue]);
        } catch (\Throwable $e) {
            // Si incluso el fallback falla, log y continúa con valor original
            Log::error('Fallback sanitization failed', [
                'field' => $field,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * SECURITY FIX: Sanitiza campo email
     * @param Request $request
     * @return void
     */
    private function sanitizeEmailField(Request $request): void
    {
        if (!$request->has('email')) {
            return;
        }

        try {
            $sanitizedEmail = SecurityHelper::sanitizeEmail($request->input('email'));
            $request->merge(['email' => $sanitizedEmail]);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Invalid email format detected', [
                'user_id' => $request->user()?->id,
                'ip_hash' => substr(hash('sha256', $request->ip()), 0, 8),
            ]);
            // Dejar que la validación posterior maneje el email inválido
        } catch (\Throwable $e) {
            Log::warning('Email sanitization error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * SECURITY FIX: Sanitiza campo locale
     * @param Request $request
     * @return void
     */
    private function sanitizeLocaleField(Request $request): void
    {
        if (!$request->has('locale')) {
            return;
        }

        try {
            $sanitizedLocale = SecurityHelper::sanitizeLocale($request->input('locale'));
            $request->merge(['locale' => $sanitizedLocale]);
        } catch (\Throwable $e) {
            Log::debug('Locale sanitization failed', ['error' => $e->getMessage()]);
            // Permitir que la validación posterior maneje locales inválidos
        }
    }

    /**
     * SECURITY FIX: Sanitiza parámetros de la ruta
     * @param Request $request
     * @return void
     */
    private function sanitizeRouteParameters(Request $request): void
    {
        $route = $request->route();
        if (!$route) {
            return;
        }

        $parameters = $route->parameters();

        // Sanitizar locale en parámetros de ruta
        if (isset($parameters['locale'])) {
            try {
                $sanitizedLocale = SecurityHelper::sanitizeLocale($parameters['locale']);
                $route->setParameter('locale', $sanitizedLocale);
            } catch (\Throwable $e) {
                Log::debug('Route locale sanitization failed', ['error' => $e->getMessage()]);
            }
        }

        // Sanitizar otros parámetros críticos
        $this->sanitizeSpecificParameters($route, $parameters);
    }

    /**
     * SECURITY FIX: Sanitiza parámetros específicos de la ruta
     * @param mixed $route
     * @param array $parameters
     * @return void
     */
    private function sanitizeSpecificParameters($route, array $parameters): void
    {
        $parametersToSanitize = ['slug', 'token'];

        foreach ($parametersToSanitize as $param) {
            if (isset($parameters[$param])) {
                try {
                    $sanitizedValue = SecurityHelper::sanitizeUserInput($parameters[$param]);
                    $route->setParameter($param, $sanitizedValue);
                } catch (\Throwable $e) {
                    Log::debug("Route parameter '{$param}' sanitization failed", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // ID: validar que sea numérico antes de sanitizar
        if (isset($parameters['id']) && is_numeric($parameters['id'])) {
            $route->setParameter('id', (int) $parameters['id']);
        }
    }
}
