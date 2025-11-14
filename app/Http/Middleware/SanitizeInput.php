<?php

namespace App\Http\Middleware;

use App\Helpers\SecurityHelper;
use App\Http\Requests\Concerns\SanitizesInputs;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
    use SanitizesInputs;

    private ?array $cachedFieldMap = null;
    private ?array $cachedRouteParamMap = null;

    /**
     * Valor centinela aplicado cuando la sanitización de email falla para que la validación posterior lo rechace explícitamente.
     */
    private const INVALID_EMAIL_PLACEHOLDER = '__invalid_email__';

    private const FIELD_SANITIZATION_MAP = [
        'name' => 'sanitizeUserName',
        'description' => 'sanitizePlainText',
        'content' => 'sanitizeHtml',
        'message' => 'sanitizePlainText',
        'comment' => 'sanitizePlainText',
        'title' => 'sanitizePlainText',
        'bio' => 'sanitizePlainText',
    ];

    private const CONFIG_KEY_FIELDS = 'security.sanitize.fields';
    private const CONFIG_KEY_ROUTE_PARAMS = 'security.sanitize.route_params';
    private const PROTECTED_FIELDS = ['email', 'password'];

    /**
     * Mapeo de parámetros de ruta y su sanitización.
     */
    private const ROUTE_PARAM_SANITIZATION_MAP = [
        'slug' => 'sanitizePlainText',
        'token' => 'sanitizeToken',
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
     * Nota: cuando la sanitización de email falla, se inserta un valor centinela
     * (`__invalid_email__`) para que las reglas de validación subsecuentes lo identifiquen
     * y puedan devolver un error coherente.
     *
     * @param Request $request
     * @return void
     */
    private function sanitizeRequestFields(Request $request): void
    {
        foreach ($this->getFieldSanitizationMap() as $field => $method) {
            if ($request->has($field)) {
                $this->sanitizeField($request, $field, $method);
            }
        }

        // Campos especiales con manejo específico
        $this->sanitizeEmailField($request);
        $this->sanitizeLocaleField($request);
    }

    /**
     * Sanitiza un campo concreto usando un método reutilizable (trait).
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
            $sanitizedValue = $this->sanitizeFieldValue($original, $method, $field);
            $request->merge([$field => $sanitizedValue]);
        } catch (\Throwable $e) {
            Log::warning('Field sanitization failed', [
                'field' => $field,
                'method' => $method,
                'user_id' => $request->user()?->id,
                'ip_hash' => substr(hash('sha256', $request->ip()), 0, 8),
                'error' => $e->getMessage(),
            ]);

            $fallback = is_scalar($original) ? (string) $original : '';
            $request->merge([$field => $this->sanitizeFallback($fallback)]);
        }
    }

    /**
     * Sanitiza el campo `email` si está presente.
     */
    private function sanitizeEmailField(Request $request): void
    {
        if (!$request->has('email')) {
            return;
        }

        $email = $request->input('email');
        $sanitized = $this->sanitizeValueSilently('sanitizeEmail', $email, 'email', $request);
        if ($sanitized !== null) {
            $request->merge(['email' => $sanitized]);
            return;
        }

        // Introducir valor centinela para que la validación posterior informe el error.
        $request->merge(['email' => self::INVALID_EMAIL_PLACEHOLDER]);
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

        $locale = $request->input('locale');
        $sanitized = $this->sanitizeValueSilently('sanitizeLocale', $locale, 'locale', $request, logLevel: 'debug');
        if ($sanitized !== null) {
            $request->merge(['locale' => $sanitized]);
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
        if (!$route instanceof Route) {
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

        $this->sanitizeSpecificParameters($route, $parameters);
    }

    /**
     * Sanitiza parámetros específicos de la ruta: `slug`, `token` y `id` (numérica).
     *
     * @param Route $route      Instancia de Route.
     * @param array $parameters Parámetros actuales de la ruta.
     * @return void
     */
    private function sanitizeSpecificParameters(Route $route, array $parameters): void
    {
        foreach ($this->getRouteParamSanitizationMap() as $param => $method) {
            if (!array_key_exists($param, $parameters)) {
                continue;
            }

            $value = $parameters[$param];
            $sanitized = $this->sanitizeRouteValue($method, $value, "route.{$param}");
            if ($sanitized !== null) {
                $route->setParameter($param, $sanitized);
            }
        }

        // ID: validar que sea numérico antes de sanitizar
        if (array_key_exists('id', $parameters)) {
            $rawId = $parameters['id'];
            $intValue = $this->normalizeRouteId($rawId);

            if ($intValue !== null) {
                $route->setParameter('id', $intValue);
            } else {
                Log::debug('Route parameter id rejected', [
                    'value' => $rawId,
                ]);
            }
        }
    }

    /**
     * Sanitiza valores sin propagar excepciones y opcionalmente registra logs.
     */
    private function sanitizeValueSilently(
        string $method,
        mixed $value,
        string $context,
        Request $request,
        string $logLevel = 'warning'
    ): ?string {
        try {
            return $this->sanitizeFieldValue($value, $method, $context);
        } catch (\Throwable $e) {
            Log::log($logLevel, "{$context}_sanitization_failed", [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'ip_hash' => substr(hash('sha256', (string) $request->ip()), 0, 8),
            ]);

            return null;
        }
    }

    /**
     * Sanitiza parámetros de ruta usando SecurityHelper con manejo seguro.
     */
    private function sanitizeRouteValue(string $method, mixed $value, string $context): ?string
    {
        $normalizedMethod = $this->normalizeSanitizationMethod($method);
        if ($normalizedMethod === null) {
            Log::error('Route sanitization method not allowed', [
                'method' => $method,
                'context' => $context,
            ]);
            return null;
        }

        if (!is_string($value) && !is_numeric($value) && !is_bool($value)) {
            Log::debug('Route parameter has unsupported type', [
                'context' => $context,
                'type' => get_debug_type($value),
            ]);
            return null;
        }

        try {
            return call_user_func([SecurityHelper::class, $normalizedMethod], (string) $value);
        } catch (\Throwable $e) {
            Log::debug("Route parameter sanitization failed", [
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Filtra/valida configuraciones dinámicas de sanitización contra la whitelist.
     *
     * @param array  $map
     * @param string $configKey
     * @param bool   $allowProtected
     * @return array
     */
    private function filterSanitizationMap(array $map, string $configKey, bool $allowProtected = true): array
    {
        $validated = [];

        foreach ($map as $field => $method) {
            if (!\is_string($field) || $field === '') {
                Log::warning('Sanitization map key must be a non-empty string', [
                    'config_key' => $configKey,
                    'provided_key' => $field,
                ]);
                continue;
            }

            if (!$allowProtected && \in_array($field, self::PROTECTED_FIELDS, true)) {
                Log::warning('Attempt to override protected field sanitization', [
                    'field' => $field,
                    'config_key' => $configKey,
                ]);
                continue;
            }

            if (!\is_string($method) || $method === '') {
                Log::warning('Sanitization method must be string', [
                    'field' => $field,
                    'config_key' => $configKey,
                ]);
                continue;
            }

            if (!$this->isSanitizationMethodAllowed($method)) {
                Log::error('Sanitization method rejected', [
                    'field' => $field,
                    'method' => $method,
                    'config_key' => $configKey,
                ]);
                continue;
            }

            $validated[$field] = $method;
        }

        return $validated;
    }

    /**
     * Devuelve el mapa de sanitización para campos del request (incluyendo config validada).
     */
    private function getFieldSanitizationMap(): array
    {
        if ($this->cachedFieldMap !== null) {
            return $this->cachedFieldMap;
        }

        $customFields = $this->filterSanitizationMap(
            (array) config(self::CONFIG_KEY_FIELDS, []),
            self::CONFIG_KEY_FIELDS,
            false
        );

        return $this->cachedFieldMap = array_merge(self::FIELD_SANITIZATION_MAP, $customFields);
    }

    /**
     * Devuelve el mapa de sanitización para parámetros de ruta (incluyendo config validada).
     */
    private function getRouteParamSanitizationMap(): array
    {
        if ($this->cachedRouteParamMap !== null) {
            return $this->cachedRouteParamMap;
        }

        $customParams = $this->filterSanitizationMap(
            (array) config(self::CONFIG_KEY_ROUTE_PARAMS, []),
            self::CONFIG_KEY_ROUTE_PARAMS
        );

        return $this->cachedRouteParamMap = array_merge(self::ROUTE_PARAM_SANITIZATION_MAP, $customParams);
    }

    /**
     * Normaliza el parámetro de ruta `id`, rechazando valores no enteros canónicos.
     */
    private function normalizeRouteId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (!preg_match('/^[1-9]\d*$/', $trimmed)) {
                return null;
            }

            return (int) $trimmed;
        }

        return null;
    }
}
