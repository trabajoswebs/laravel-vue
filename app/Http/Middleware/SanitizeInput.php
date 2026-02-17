<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityHelper;
use App\Http\Requests\Concerns\SanitizesInputs;
use App\Infrastructure\Sanitization\DisplayName;
use App\Infrastructure\Localization\TranslationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;

/**
 * Middleware de Sanitización de Entrada de Usuario
 *
 * Este middleware intercepta todas las solicitudes HTTP entrantes para aplicar
 * sanitización automática a los datos de entrada (cuerpo de la solicitud, 
 * parámetros de consulta y parámetros de ruta). Su propósito principal es:
 *
 * - Reducir la superficie de ataque contra vulnerabilidades como XSS (Cross-Site Scripting)
 * - Prevenir inyección de contenido malicioso
 * - Homogenizar y normalizar los datos antes de que lleguen a los controladores
 * - Establecer una capa de seguridad adicional en el borde de la aplicación
 *
 * Características importantes:
 * - **No realiza validación** - solo normaliza/sanitiza los datos
 * - Utiliza métodos whitelisteados de SecurityHelper para garantizar seguridad
 * - No altera métodos HTTP ni añade campos inexistentes
 * - Solo modifica (merge) campos que ya existen en la solicitud
 * - Incluye logging detallado de intentos de entrada sospechosos
 * - Soporta configuración personalizada vía archivos de configuración
 * - Maneja casos especiales como emails, locales y nombres de usuario
 *
 * La validación real debe realizarse posteriormente con FormRequests o reglas
 * de validación en los controladores.
 */
class SanitizeInput
{
    use SanitizesInputs;

    /**
     * @var array|null Cache para el mapa de sanitización de campos del request
     */
    private ?array $cachedFieldMap = null;

    /**
     * @var array|null Cache para el mapa de sanitización de parámetros de ruta
     */
    private ?array $cachedRouteParamMap = null;

    /**
     * Valor centinela utilizado cuando la sanitización de email falla.
     *
     * Este valor especial se inserta en lugar del email original cuando la
     * sanitización falla, permitiendo que las reglas de validación posteriores
     * (como FormRequests) puedan identificar y rechazar explícitamente el valor
     * problemático con un mensaje de error coherente.
     */
    private const INVALID_EMAIL_PLACEHOLDER = '__invalid_email__';

    /**
     * Mapa estático de campos comunes y sus métodos de sanitización predeterminados.
     *
     * Define qué tipo de sanitización se aplica a campos con nombres específicos
     * que son comúnmente utilizados en formularios y APIs. Los métodos deben
     * estar disponibles en SecurityHelper y estar en la lista blanca de métodos permitidos.
     *
     * @var array<string, string>
     */
    private const FIELD_SANITIZATION_MAP = [
        'description' => 'sanitizePlainText', // Sanitiza texto plano, elimina HTML peligroso
        'content' => 'sanitizeHtml',        // Sanitiza HTML permitiendo etiquetas seguras
        'message' => 'sanitizePlainText',   // Sanitiza texto plano para mensajes
        'comment' => 'sanitizePlainText',   // Sanitiza texto plano para comentarios
        'title' => 'sanitizePlainText',     // Sanitiza texto plano para títulos
        'bio' => 'sanitizePlainText',       // Sanitiza texto plano para biografías
    ];

    /**
     * Claves de configuración para mapeos dinámicos de sanitización.
     *
     * Estas constantes apuntan a las ubicaciones en los archivos de configuración
     * donde se pueden definir mapeos personalizados de sanitización para campos
     * y parámetros de ruta adicionales.
     */
    private const CONFIG_KEY_FIELDS = 'security.sanitize.fields';
    private const CONFIG_KEY_ROUTE_PARAMS = 'security.sanitize.route_params';

    /**
     * Lista de campos protegidos que no deben ser sobreescritos por configuración.
     *
     * Estos campos tienen un tratamiento especial y no deben ser modificados
     * por configuración dinámica para evitar posibles problemas de seguridad.
     */
    private const PROTECTED_FIELDS = ['email', 'password'];

    /**
     * Mapa estático de parámetros de ruta y sus métodos de sanitización.
     *
     * Define la sanitización predeterminada para parámetros de ruta comunes
     * que se utilizan frecuentemente en las rutas de la aplicación.
     *
     * @var array<string, string>
     */
    private const ROUTE_PARAM_SANITIZATION_MAP = [
        'slug' => 'sanitizePlainText', // Sanitiza slugs de URLs
        'token' => 'sanitizeToken',    // Sanitiza tokens de autenticación/verificación
    ];

    /**
     * Punto de entrada principal del middleware.
     *
     * Este método es llamado por el pipeline de Laravel para procesar la solicitud.
     * Aplica sanitización tanto a los campos del cuerpo/consulta de la solicitud
     * como a los parámetros de ruta antes de pasar la solicitud al siguiente
     * middleware o controlador.
     *
     * @param Request $request Solicitud HTTP entrante que será procesada
     * @param Closure $next    Función que representa el siguiente paso en el pipeline
     * @return mixed           La respuesta generada por el siguiente middleware/controlador
     */
    public function handle(Request $request, Closure $next)
    {
        $this->sanitizeRequestFields($request);
        $this->sanitizeRouteParameters($request);

        return $next($request);
    }

    /**
     * Sanitiza los campos críticos del cuerpo y parámetros de consulta de la solicitud.
     *
     * Este método aplica sanitización a campos definidos en el mapa estático
     * y dinámico, así como a campos especiales que requieren tratamiento particular
     * como 'email', 'locale', y 'name' (usando el Value Object DisplayName).
     *
     * @param Request $request Solicitud HTTP a procesar
     * @return void
     */
    private function sanitizeRequestFields(Request $request): void
    {
        $this->sanitizeDisplayNameField($request);

        // Procesa campos según el mapa de sanitización
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
     * Sanitiza el campo 'name' (nombre visible de usuario) usando el Value Object DisplayName.
     *
     * Este campo recibe tratamiento especial ya que puede contener caracteres
     * especiales y requiere una validación más estricta. Si la sanitización falla,
     * se registra un warning y se aplica un fallback de seguridad.
     *
     * @param Request $request Solicitud HTTP a procesar
     * @return void
     */
    private function sanitizeDisplayNameField(Request $request): void
    {
        if (!$request->has('name')) {
            return;
        }

        $displayName = DisplayName::from($request->input('name'));

        if ($displayName->isValid()) {
            $request->merge(['name' => $displayName->sanitized()]);
            return;
        }

        // Registra el intento fallido de sanitización para auditoría
        Log::warning('Display name sanitization failed', [
            'field' => 'name',
            'user_id' => $request->user()?->id,
            'ip_hash' => substr(hash('sha256', (string) $request->ip()), 0, 8), // Hash truncado para privacidad
            'error' => $displayName->errorMessage(),
        ]);

        // Aplica fallback seguro en lugar de rechazar completamente
        $request->merge(['name' => $this->sanitizeFallback($displayName->original())]);
    }

    /**
     * Sanitiza un campo específico usando el método correspondiente.
     *
     * Este método encapsula la lógica de sanitización individual de campos,
     * incluyendo manejo de excepciones y logging de errores para auditoría.
     *
     * @param Request $request Solicitud HTTP que contiene el campo
     * @param string  $field   Nombre del campo a sanitizar
     * @param string  $method  Nombre del método de sanitización a usar (debe estar permitido)
     * @return void
     */
    private function sanitizeField(Request $request, string $field, string $method): void
    {
        $original = $request->input($field);

        try {
            $sanitizedValue = $this->sanitizeFieldValue($original, $method, $field);
            $request->merge([$field => $sanitizedValue]);
        } catch (\Throwable $e) {
            // Registra errores de sanitización para monitoreo y análisis
            Log::warning('Field sanitization failed', [
                'field' => $field,
                'method' => $method,
                'user_id' => $request->user()?->id,
                'ip_hash' => substr(hash('sha256', $request->ip()), 0, 8),
                'error' => $e->getMessage(),
            ]);

            // Aplica fallback seguro si la sanitización falla
            $fallback = is_scalar($original) ? (string) $original : '';
            $request->merge([$field => $this->sanitizeFallback($fallback)]);
        }
    }

    /**
     * Sanitiza el campo de email con manejo especial.
     *
     * El campo email recibe tratamiento especial: si la sanitización falla,
     * se inserta un valor centinela en lugar de rechazarlo completamente,
     * permitiendo que las reglas de validación posteriores manejen el error
     * de forma coherente.
     *
     * @param Request $request Solicitud HTTP que contiene el campo email
     * @return void
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

        // Inserta valor centinela para que la validación posterior pueda detectarlo
        $request->merge(['email' => self::INVALID_EMAIL_PLACEHOLDER]);
    }

    /**
     * Sanitiza el campo de locale con manejo no bloqueante.
     *
     * El campo locale es opcional y su error de sanitización no detiene
     * el flujo principal. La validación posterior (por ejemplo, con Rule::in([...])
     * en FormRequests) se encargará de rechazar valores inválidos.
     *
     * @param Request $request Solicitud HTTP que contiene el campo locale
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
     * Sanitiza los parámetros de la ruta (route parameters).
     *
     * Este método procesa los parámetros que vienen en la URL (por ejemplo,
     * en rutas como /users/{id}/posts/{slug}). Aplica sanitización específica
     * a parámetros comunes como 'locale', 'slug', 'token', y 'id'.
     *
     * @param Request $request Solicitud HTTP que contiene los parámetros de ruta
     * @return void
     */
    private function sanitizeRouteParameters(Request $request): void
    {
        $route = $request->route();
        if (!$route instanceof Route) {
            return; // No hay ruta para procesar
        }

        $parameters = $route->parameters();

        // Sanitización especial para locale en parámetros de ruta
        if (isset($parameters['locale'])) {
            try {
                // No fallback aquí: si es inválido, dejamos que el controller lo rechace.
                $sanitizedLocale = TranslationService::sanitizeLocale((string) $parameters['locale']);
                if ($sanitizedLocale !== '') {
                    $route->setParameter('locale', $sanitizedLocale);
                }
            } catch (\Throwable $e) {
                Log::debug('Route locale sanitization failed', ['error' => $e->getMessage()]);
            }
        }

        $this->sanitizeSpecificParameters($route, $parameters);
    }

    /**
     * Sanitiza parámetros específicos de la ruta con tratamiento individualizado.
     *
     * Este método aplica sanitización a parámetros como 'slug', 'token', y 'id'
     * según sus reglas específicas. El parámetro 'id' recibe validación especial
     * para asegurar que sea un valor numérico válido antes de la sanitización.
     *
     * @param Route $route      Instancia de la ruta actual
     * @param array $parameters Array de parámetros de la ruta
     * @return void
     */
    private function sanitizeSpecificParameters(Route $route, array $parameters): void
    {
        foreach ($this->getRouteParamSanitizationMap() as $param => $method) {
            if (!array_key_exists($param, $parameters)) {
                continue; // Parámetro no está presente
            }

            $value = $parameters[$param];
            $sanitized = $this->sanitizeRouteValue($method, $value, "route.{$param}");
            if ($sanitized !== null) {
                $route->setParameter($param, $sanitized);
            }
        }

        // Validación especial para parámetro 'id': debe ser numérico
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
     * Sanitiza un valor sin propagar excepciones, opcionalmente registrando logs.
     *
     * Método auxiliar para sanitizar valores que pueden fallar sin interrumpir
     * el flujo principal de la aplicación. Útil para campos no críticos.
     *
     * @param string  $method    Método de sanitización a usar
     * @param mixed   $value     Valor a sanitizar
     * @param string  $context   Contexto para logging (nombre del campo)
     * @param Request $request   Solicitud actual para información de contexto
     * @param string  $logLevel  Nivel de log a usar ('debug', 'warning', etc.)
     * @return string|null       Valor sanitizado o null si falló
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
     * Sanitiza un valor de parámetro de ruta con manejo seguro de errores.
     *
     * Este método encapsula la sanitización de parámetros de ruta, incluyendo
     * validación del método permitido, verificación de tipos soportados,
     * y manejo de excepciones sin interrumpir el flujo principal.
     *
     * @param string $method  Nombre del método de sanitización
     * @param mixed  $value   Valor del parámetro de ruta
     * @param string $context Contexto para logging
     * @return string|null    Valor sanitizado o null si falló
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

        // Verifica que el valor sea de un tipo soportado para sanitización
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
     * Filtra y valida un mapa de sanitización contra la lista blanca de métodos permitidos.
     *
     * Este método asegura que solo se utilicen métodos de sanitización seguros
     * y autorizados, previniendo el uso de métodos potencialmente peligrosos.
     * También verifica que las claves y valores del mapa sean válidos.
     *
     * @param array  $map              Mapa de campo => método a validar
     * @param string $configKey        Clave de configuración para logging
     * @param bool   $allowProtected   Si se permiten campos protegidos
     * @return array                   Mapa filtrado y validado
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
     * Obtiene el mapa de sanitización de campos con cache.
     *
     * Combina el mapa estático predeterminado con configuraciones personalizadas
     * del archivo de configuración, aplicando validación de seguridad.
     * Utiliza cache para evitar recálculos innecesarios.
     *
     * @return array Mapa combinado de campo => método de sanitización
     */
    private function getFieldSanitizationMap(): array
    {
        if ($this->cachedFieldMap !== null) {
            return $this->cachedFieldMap;
        }

        $customFields = $this->filterSanitizationMap(
            (array) config(self::CONFIG_KEY_FIELDS, []),
            self::CONFIG_KEY_FIELDS,
            false // No permite campos protegidos
        );

        return $this->cachedFieldMap = array_merge(self::FIELD_SANITIZATION_MAP, $customFields);
    }

    /**
     * Obtiene el mapa de sanitización de parámetros de ruta con cache.
     *
     * Combina el mapa estático predeterminado con configuraciones personalizadas
     * para parámetros de ruta, aplicando validación de seguridad.
     * Utiliza cache para evitar recálculos innecesarios.
     *
     * @return array Mapa combinado de parámetro => método de sanitización
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
     * Normaliza y valida el parámetro de ruta 'id'.
     *
     * Este método aplica validación estricta al parámetro 'id' para asegurar que
     * sea un número entero positivo válido, dentro de los límites de PHP_INT_MAX
     * y sin ceros a la izquierda. Rechaza cadenas vacías, valores no numéricos,
     * overflow y discrepancias entre la cadena original y su conversión.
     *
     * Ejemplos:
     * - "123"               -> 123  -> "123" (OK)
     * - "000123"            -> rechazado (ceros a la izquierda los invalidan)
     * - "9999999999999999999999" -> rechazado (más allá de PHP_INT_MAX)
     *
     * @param mixed $value Valor del parámetro id a normalizar
     * @return int|null Valor entero positivo o null si inválido
     */
    private function normalizeRouteId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (!ctype_digit($trimmed) || $trimmed[0] === '0') {
            return null;
        }

        $maxIntString = (string) PHP_INT_MAX;
        $maxDigits = strlen($maxIntString);
        if (strlen($trimmed) > $maxDigits) {
            return null;
        }

        if (strlen($trimmed) === $maxDigits && strcmp($trimmed, $maxIntString) > 0) {
            return null;
        }

        $id = (int) $trimmed;
        $normalized = (string) $id;

        if ($normalized !== $trimmed) {
            return null;
        }

        return $id;
    }
}
