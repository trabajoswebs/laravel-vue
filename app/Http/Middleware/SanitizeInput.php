<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Helpers\SecurityHelper;
use Illuminate\Support\Facades\Log;

/**
 * Class SanitizeInput
 *
 * Middleware que sanitiza entradas del usuario en cuerpo y parámetros de ruta
 * antes de llegar a tus controladores. Su objetivo es reducir superficie de
 * ataque (XSS, inyección de contenido) y homogenizar datos para validación.
 *
 * Nota: Este middleware **no valida**; solo normaliza/sanitiza. La validación
 * debe hacerse igualmente con FormRequests o reglas en el controlador.
 *
 * Requisitos:
 * - Métodos usados provienen de {@see SecurityHelper} y están **whitelisteados**.
 * - No altera métodos HTTP ni añade campos; solo hace `merge()` sobre los existentes.
 */
class SanitizeInput
{
    /**
     * Lista blanca de métodos permitidos de SecurityHelper para invocación dinámica.
     *
     * @var array<int,string>
     */
    private const ALLOWED_METHODS = [
        'sanitizeUserName',
        'sanitizeUserInput',
        'sanitizeHtml',
        'sanitizeEmail',
        'sanitizeLocale',
        'sanitizePlainText',
    ];

    /**
     * Mapeo campo→método de sanitización a aplicar sobre el payload de la request.
     *
     * @var array<string,string>
     */
    private const FIELD_SANITIZATION_MAP = [
        'name' => 'sanitizeUserName',
        'description' => 'sanitizePlainText',
        'content' => 'sanitizeHtml',
        'message' => 'sanitizePlainText',
        'comment' => 'sanitizePlainText',
        'title' => 'sanitizePlainText',
        'bio' => 'sanitizePlainText',
    ];

    /**
     * Punto de entrada del middleware.
     *
     * @param Request $request Solicitud HTTP entrante.
     * @param Closure $next    Siguiente manejador del pipeline.
     * @return mixed           Respuesta HTTP generada por el siguiente middleware/controlador.
     */
    public function handle(Request $request, Closure $next)
    {
        $this->sanitizeRequestFields($request);
        $this->sanitizeRouteParameters($request);

        return $next($request);
    }

    /**
     * Sanitiza campos críticos del body/query de la request según FIELD_SANITIZATION_MAP
     * y aplica tratamientos específicos para `email` y `locale`.
     *
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

        // Campos especiales con manejo específico
        $this->sanitizeEmailField($request);
        $this->sanitizeLocaleField($request);
    }

    /**
     * Sanitiza un campo concreto usando un método permitido de SecurityHelper.
     * En caso de error, registra log y aplica un fallback seguro.
     *
     * @param Request $request
     * @param string  $field   Nombre del campo a sanitizar.
     * @param string  $method  Método de SecurityHelper a usar (debe estar en ALLOWED_METHODS).
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

            // Fallback seguro (texto plano higienizado)
            $this->applyFallbackSanitization($request, $field, $original);
        }
    }

    /**
     * Aplica la sanitización delegando en SecurityHelper.
     * Acepta arrays (aplicación recursiva) o escalares.
     *
     * @param mixed  $value  Valor original (string|array|scalar).
     * @param string $method Nombre del método de SecurityHelper a invocar.
     * @return mixed         Valor sanitizado (misma estructura que el original).
     *
     * @throws \InvalidArgumentException si el método no está permitido.
     * @throws \RuntimeException         si el método no existe en SecurityHelper.
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
     * Fallback de sanitización: convierte a string (si es escalar) y aplica sanitizePlainText.
     * Registra error si también falla el fallback y mantiene el valor original.
     *
     * @param Request $request
     * @param string  $field    Nombre del campo que se está sanitizando.
     * @param mixed   $original Valor original del campo.
     * @return void
     */
    private function applyFallbackSanitization(Request $request, string $field, $original): void
    {
        try {
            $scalarOriginal = is_scalar($original) ? (string) $original : '';
            $fallbackValue = SecurityHelper::sanitizePlainText($scalarOriginal);
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
     * Sanitiza el campo `email` si está presente. No fuerza formato válido;
     * si es inválido, se delega a la validación posterior.
     *
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
     * Sanitiza el campo `locale` si está presente. Errores no bloquean; quedan a cargo
     * de la validación posterior (por ejemplo, Rule::in([...]) en FormRequest).
     *
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
     * Sanitiza parámetros de la ruta (route params). Cubre `locale`, `slug`, `token`
     * y normaliza `id` si es numérica.
     *
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
     * Sanitiza parámetros específicos de la ruta: `slug`, `token` y `id` (numérica).
     *
     * @param mixed $route      Instancia de Route (tipo laxo para evitar dependencia fuerte en firma).
     * @param array $parameters Parámetros actuales de la ruta.
     * @return void
     */
    private function sanitizeSpecificParameters($route, array $parameters): void
    {
        $parametersToSanitize = ['slug', 'token'];

        foreach ($parametersToSanitize as $param) {
            if (isset($parameters[$param])) {
                try {
                    $value = $parameters[$param];
                    $sanitizedValue = $param === 'token'
                        ? SecurityHelper::sanitizeToken((string) $value)
                        : SecurityHelper::sanitizePlainText((string) $value);
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
