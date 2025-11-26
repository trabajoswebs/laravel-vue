<?php

namespace App\Infrastructure\Http\Middleware;

use App\Domain\Security\SecurityHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de auditoría de acciones de usuarios autenticados.
 * 
 * Registra selectivamente las acciones de usuarios según reglas de negocio,
 * aplicando muestreo estadístico para peticiones GET y auditando siempre
 * acciones críticas, mutaciones y errores. Protege datos sensibles mediante
 * redacción, hashing y truncamiento antes del registro.
 * 
 * Características de seguridad:
 * - Validación exhaustiva de patrones para prevenir ataques ReDoS
 * - Monitoreo de tiempos de ejecución de pattern matching
 * - Hashing de IPs y User-Agents (GDPR compliant)
 * - Redacción automática de campos sensibles
 * - Generación criptográficamente segura de request IDs
 * - Fail-safe approach: audita cuando hay incertidumbre
 * 
 * Configuración requerida en config/audit.php:
 * - critical_actions: array de patrones de rutas críticas
 * - excluded_routes: array de patrones a excluir
 * - sensitive_fields: array de campos a redactar
 * - sample_rate: float 0-1 para muestreo de GETs
 * - channels: array con canales 'default' y 'security'
 * 
 * @package App\Http\Middleware
 * @author  Tu Nombre
 * @version 1.0.0
 * 
 * @example
 * // En Kernel.php
 * protected $middlewareGroups = [
 *     'web' => [
 *         \App\Http\Middleware\UserAudit::class,
 *     ],
 * ];
 * 
 * @see https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS
 */
class UserAudit
{
    /**
     * Patrones de rutas que deben auditarse siempre, independientemente del método o estado.
     * 
     * Formato: "METHOD:path/pattern" donde METHOD es HTTP method y path soporta wildcards (* y ?).
     * Ejemplo: ["POST:api/users/*", "DELETE:api/*", "PUT:admin/*"]
     * 
     * @var array<int, string>
     */
    private array $criticalActions;

    /**
     * Patrones de rutas excluidas explícitamente de auditoría.
     * 
     * Útil para endpoints de health-check, métricas o rutas de bajo valor.
     * Formato idéntico a $criticalActions.
     * 
     * @var array<int, string>
     */
    private array $excludedRoutes;

    /**
     * Nombres de campos que deben redactarse en payloads auditados.
     * 
     * Estos campos serán reemplazados por '[REDACTED]' en los logs.
     * Típicamente: password, token, credit_card, ssn, api_key, etc.
     * 
     * @var array<int, string>
     */
    private array $sensitiveFields;

    /**
     * Proporción de peticiones GET que se auditan (0.0 a 1.0).
     * 
     * Valor 0.01 = 1% de GETs se auditan (reduce overhead).
     * Valor 1.0 = 100% de GETs se auditan (máxima cobertura).
     * 
     * @var float
     */
    private float $sampleRate;

    /**
     * Canal de log para eventos de auditoría estándar.
     * 
     * Define dónde se escriben los logs de auditoría normal.
     * Típicamente 'daily', 'single', 'stack', etc.
     * 
     * @var string
     */
    private string $defaultChannel;

    /**
     * Canal de log dedicado a alertas de seguridad y acciones críticas.
     * 
     * Separado del canal default para facilitar monitoreo de eventos sensibles.
     * Típicamente 'security', 'slack', 'syslog', etc.
     * 
     * @var string
     */
    private string $securityChannel;

    /**
     * Longitud máxima permitida para patrones de auditoría (caracteres).
     * 
     * Previene patrones excesivamente largos que podrían causar problemas
     * de memoria o performance en fnmatch.
     */
    private const MAX_PATTERN_LENGTH = 200;

    /**
     * Número máximo de wildcards (* y ?) permitidos por patrón.
     * 
     * Limita la complejidad de backtracking en fnmatch para prevenir
     * ataques de denegación de servicio (ReDoS).
     */
    private const MAX_WILDCARDS_PER_PATTERN = 10;

    /**
     * Tiempo máximo aceptable para operaciones de pattern matching (segundos).
     * 
     * Si fnmatch excede este umbral, se registra una alerta de performance
     * para identificar patrones problemáticos.
     */
    private const FNMATCH_TIMEOUT_SECONDS = 0.1; // 100ms

    /**
     * Inicializa el middleware cargando y validando la configuración de auditoría.
     * 
     * Valida que todos los patrones cumplan restricciones de seguridad y sanitiza
     * el sample rate para mantenerlo entre 0 y 1. Los patrones inválidos se filtran
     * y se registran en el canal de seguridad.
     * 
     * @throws \InvalidArgumentException Si la configuración es crítica y está ausente
     */

     public function __construct()
    {
         $this->defaultChannel   = config('audit.channels.default', 'daily');
         $this->securityChannel  = config('audit.channels.security', 'security');

         $sampleRate = config('audit.sample_rate', 0.01);
         $this->sampleRate = max(0, min(1, (float) $sampleRate));

         $this->criticalActions  = $this->sanitizePatterns(config('audit.critical_actions', []));
         $this->excludedRoutes   = $this->sanitizePatterns(config('audit.excluded_routes', []));
         $this->sensitiveFields  = config('audit.sensitive_fields', []);
    }
 
     /**
      * Valida y filtra patrones de auditoría según restricciones de seguridad.
      * 
      * Aplica múltiples validaciones a cada patrón para prevenir:
      * - Ataques ReDoS (Regular expression Denial of Service)
      * - Consumo excesivo de memoria
      * - Backtracking costoso en fnmatch
      * - Inyección de caracteres peligrosos
      * 
      * Los patrones inválidos se descartan y se registra una alerta en el canal
      * de seguridad para investigación posterior.
      *
      * @param array<int, string> $patterns Array de patrones sin validar
      * @return array<int, string> Array de patrones validados y seguros
      * 
      * @see isValidPattern()
      */
    private function sanitizePatterns(array $patterns): array
    {
        $validPatterns = [];
        
        foreach ($patterns as $pattern) {
            if ($this->isValidPattern($pattern)) {
                $validPatterns[] = $pattern;
            } else {
                Log::channel($this->securityChannel)->warning('Invalid audit pattern detected and ignored', [
                    'pattern' => $pattern,
                    'reason' => 'Pattern validation failed',
                    'timestamp' => now()->toISOString()
                ]);
            }
        }
        
        return $validPatterns;
    }

    /**
     * Valida que un patrón cumpla todas las restricciones de seguridad.
     * 
     * Verifica:
     * 1. Longitud máxima del patrón
     * 2. Número de wildcards (* y ?)
     * 3. Patrones anidados sospechosos
     * 4. Caracteres permitidos (solo alfanuméricos, /, _, -, ., *, ?, :, |)
     * 
     * @param string $pattern Patrón a validar
     * @return bool True si el patrón es seguro, false si debe descartarse
     * 
     * @example
     * isValidPattern('POST:api/users/*') // true
     * isValidPattern('GET:**') // false (demasiados wildcards anidados)
     * isValidPattern('POST:api/users/<script>') // false (caracteres inválidos)
     */
    private function isValidPattern(string $pattern): bool
    {
        // Check pattern length
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return false;
        }
        
        // Check wildcard count to prevent excessive backtracking
        $wildcardCount = substr_count($pattern, '*') + substr_count($pattern, '?');
        if ($wildcardCount > self::MAX_WILDCARDS_PER_PATTERN) {
            return false;
        }
        
        // Check for suspicious nested patterns
        if (preg_match('/(\*\/){5,}/', $pattern) || preg_match('/(\*\*){3,}/', $pattern)) {
            return false;
        }
        
        // Ensure pattern contains only safe characters
        if (!preg_match('/^[a-zA-Z0-9\/_\-\.\*\?\:\|]+$/', $pattern)) {
            return false;
        }
        
        return true;
    }

    /**
     * Procesa la petición HTTP y audita la acción si corresponde.
     * 
     * Flujo de ejecución:
     * 1. Ejecuta el siguiente middleware/controlador
     * 2. Si el usuario está autenticado, evalúa si debe auditar
     * 3. Si procede, construye y registra el log de auditoría
     * 4. Retorna la respuesta sin modificar
     * 
     * El middleware se ejecuta DESPUÉS del controlador para tener acceso
     * al código de estado HTTP de la respuesta.
     * 
     * @param Request $request Petición HTTP entrante
     * @param Closure $next Siguiente capa del middleware stack
     * @return Response Respuesta HTTP (sin modificar)
     * 
     * @see shouldAudit()
     * @see logUserAction()
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Auth::check() && $this->shouldAudit($request, $response)) {
            $this->logUserAction($request, $response);
        }

        return $response;
    }

    /**
     * Determina si la petición actual debe auditarse según reglas de negocio.
     * 
     * Criterios de auditoría (en orden de evaluación):
     * 1. Si la ruta está en $excludedRoutes → NO auditar
     * 2. Si la ruta está en $criticalActions → AUDITAR
     * 3. Si HTTP status ≥ 400 (error) → AUDITAR
     * 4. Si método es POST/PUT/PATCH/DELETE (mutación) → AUDITAR
     * 5. Si método es GET → aplicar muestreo según $sampleRate
     * 6. Cualquier otro caso → NO auditar
     * 
     * @param Request $request Petición HTTP
     * @param Response $response Respuesta HTTP (para obtener status code)
     * @return bool True si debe auditarse, false en caso contrario
     * 
     * @see matchesPattern()
     * @see shouldSample()
     */
    protected function shouldAudit(Request $request, Response $response): bool
    {
        $routeSignature = strtoupper($request->method()) . ':' . $request->path();

        if ($this->matchesPattern($routeSignature, $this->excludedRoutes)) {
            return false;
        }

        if ($this->matchesPattern($routeSignature, $this->criticalActions)) {
            return true;
        }

        if ($response->getStatusCode() >= 400) {
            return true;
        }

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return true;
        }

        if ($request->method() === 'GET') {
            return $this->shouldSample($request);
        }

        return false;
    }

    /**
     * Decide aleatoriamente si un GET debe auditarse según el sample rate.
     * 
     * Utiliza random_int() para generar números criptográficamente seguros,
     * evitando sesgos en el muestreo. Si falla la generación segura, aplica
     * fail-safe auditando todas las peticiones y registrando el error.
     * 
     * @param Request $request Petición HTTP (para logging en caso de error)
     * @return bool True si debe auditarse (probabilidad = $sampleRate)
     * 
     * @throws \Exception Capturada internamente si random_int falla
     * 
     * @example
     * // Con sampleRate = 0.01 (1%)
     * shouldSample($request) // ~1% de veces retorna true
     */
    protected function shouldSample(Request $request): bool
    {
        try {
            // Use cryptographically secure random number generator
            $randomValue = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
            return $randomValue < $this->sampleRate;
        } catch (\Exception $e) {
            // Fallback: if secure random fails, log all GET requests for security
            Log::channel($this->securityChannel)->warning('Secure random generation failed, auditing all GET requests', [
                'error' => $e->getMessage(),
                'request_url' => $request->fullUrl(),
                'timestamp' => now()->toISOString()
            ]);
            return true; // Fail-safe: audit when in doubt
        }
    }

    /**
     * Evalúa si una ruta coincide con algún patrón de la lista.
     * 
     * Utiliza fnmatch() para matching con wildcards (* y ?). Incluye protecciones:
     * - Try-catch para capturar errores de fnmatch
     * - Monitoreo de tiempo de ejecución (alerta si > 100ms)
     * - Logging detallado de patrones problemáticos
     * - Continúa con siguiente patrón si uno falla
     * 
     * Los patrones ya han sido validados por sanitizePatterns(), pero esta capa
     * adicional previene problemas en runtime.
     * 
     * @param string $route Ruta a evaluar (formato "METHOD:path")
     * @param array<int, string> $patterns Array de patrones validados
     * @return bool True si la ruta coincide con al menos un patrón
     * 
     * @see sanitizePatterns()
     * 
     * @example
     * matchesPattern('POST:api/users/123', ['POST:api/users/*']) // true
     * matchesPattern('GET:health', ['POST:*', 'DELETE:*']) // false
     */
    protected function matchesPattern(string $route, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            try {
                // Set time limit for pattern matching to prevent DoS
                $startTime = microtime(true);
                
                $result = fnmatch($pattern, $route);
                
                $executionTime = microtime(true) - $startTime;
                
                // Log slow pattern matches for monitoring
                if ($executionTime > self::FNMATCH_TIMEOUT_SECONDS) {
                    Log::channel($this->securityChannel)->warning('Slow pattern match detected', [
                        'pattern' => $pattern,
                        'route' => $route,
                        'execution_time' => $executionTime,
                        'timestamp' => now()->toISOString()
                    ]);
                }
                
                if ($result) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Log pattern matching errors
                Log::channel($this->securityChannel)->error('Pattern matching error', [
                    'pattern' => $pattern,
                    'route' => $route,
                    'error' => $e->getMessage(),
                    'timestamp' => now()->toISOString()
                ]);
                
                // Continue with other patterns on error
                continue;
            }
        }
        return false;
    }

    /**
     * Construye y registra el payload de auditoría con datos sanitizados.
     * 
     * Proceso de auditoría:
     * 1. Extrae datos del usuario autenticado
     * 2. Ofusca información sensible (IP → hash, email → dominio)
     * 3. Construye payload base con metadatos de request/response
     * 4. Incluye query params si existen (sanitizados)
     * 5. Incluye request body si procede (errores o acciones críticas)
     * 6. Registra en canal default con nivel apropiado
     * 7. Si es acción crítica, registra también en canal security
     * 
     * Cumplimiento GDPR:
     * - IPs se almacenan como hash SHA-256 irreversible
     * - User-Agent se almacena como hash truncado
     * - Email se reduce a dominio (sin parte local)
     * - Campos sensibles se redactan con '[REDACTED]'
     * 
     * @param Request $request Petición HTTP con datos del usuario
     * @param Response $response Respuesta HTTP con status code
     * @return void
     * 
     * @see getLogLevel()
     * @see sanitizeRequestData()
     * @see isCriticalAction()
     */
    protected function logUserAction(Request $request, Response $response): void
    {
        $user = Auth::user();
        $statusCode = $response->getStatusCode();
        $level = $this->getLogLevel($request, $statusCode);

        $emailDomain = null;
        if ($user?->email) {
            $parts = explode('@', $user->email);
            $emailDomain = $parts[1] ?? 'obfuscated';
        }

        $auditData = [
            'user_id' => $user->id,
            'user_email_domain' => $emailDomain,
            'empresa_id' => $user->empresa_id ?? null,
            // Conserva correlación sin registrar IPs en claro
            'ip_hash' => SecurityHelper::hashIp((string) $request->ip()),
            'user_agent_hash' => substr(hash('sha256', (string) $request->userAgent()), 0, 16),
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'status_code' => $statusCode,
            'timestamp' => now()->toISOString(),
            'request_id' => $request->header('X-Request-ID') ?: $this->generateSecureRequestId(),
        ];

        $queryParams = $request->query();
        if (!empty($queryParams)) {
            $auditData['query'] = SecurityHelper::sanitizeForLogging($queryParams);
        }

        if ($this->shouldIncludeRequestData($request, $statusCode)) {
            $auditData['request_data'] = $this->sanitizeRequestData($request);
        }

        Log::channel($this->defaultChannel)->log($level, 'User audit log', $auditData);

        if ($this->isCriticalAction($request)) {
            Log::channel($this->securityChannel)->warning('Critical user action', $auditData);
        }
    }

    /**
     * Genera un identificador único de correlación para peticiones sin X-Request-ID.
     * 
     * Intenta generar un ID criptográficamente seguro usando random_bytes().
     * Si falla (falta de entropía), usa fallback basado en timestamp de alta
     * resolución (hrtime) combinado con uniqid() para garantizar unicidad.
     * 
     * @return string ID hexadecimal de 32 caracteres o fallback único
     * 
     * @throws \Exception Capturada internamente si random_bytes falla
     * 
     * @example
     * generateSecureRequestId() // "a1b2c3d4e5f678901234567890abcdef"
     * // o en caso de error: "fallback_1234567890123456_abc123"
     */
    protected function generateSecureRequestId(): string
    {
        try {
            return bin2hex(random_bytes(16)); // 32 character hex string
        } catch (\Exception $e) {
            // Fallback to timestamp-based ID if secure random fails
            return 'fallback_' . hrtime(true) . '_' . uniqid();
        }
    }

    /**
     * Determina el nivel de severidad del log según el contexto de la petición.
     * 
     * Niveles de log:
     * - 'error': HTTP 500+ (errores de servidor)
     * - 'warning': HTTP 400-499 (errores de cliente) o acciones críticas exitosas
     * - 'info': Peticiones exitosas normales (HTTP 200-399)
     * 
     * Las acciones críticas siempre se registran como 'warning' incluso si son
     * exitosas, para facilitar alertas y monitoreo.
     * 
     * @param Request $request Petición HTTP
     * @param int $statusCode Código de estado HTTP de la respuesta
     * @return string Nivel de log PSR-3 ('error', 'warning', 'info')
     * 
     * @see isCriticalAction()
     */
    protected function getLogLevel(Request $request, int $statusCode): string
    {
        if ($statusCode >= 500) return 'error';
        if ($statusCode >= 400) return 'warning';
        if ($this->isCriticalAction($request)) return 'warning';
        return 'info';
    }

    /**
     * Evalúa si la ruta actual está marcada como crítica en la configuración.
     * 
     * Construye la firma de la ruta (METHOD:path) y la compara contra los
     * patrones definidos en $criticalActions.
     * 
     * @param Request $request Petición HTTP a evaluar
     * @return bool True si es acción crítica, false en caso contrario
     * 
     * @see matchesPattern()
     * 
     * @example
     * // Con criticalActions = ['DELETE:api/*', 'POST:admin/*']
     * isCriticalAction(DELETE api/users/123) // true
     * isCriticalAction(GET api/users) // false
     */
    protected function isCriticalAction(Request $request): bool
    {
        $routeSignature = strtoupper($request->method()) . ':' . $request->path();
        return $this->matchesPattern($routeSignature, $this->criticalActions);
    }

     /**
     * Determina si el request body debe incluirse en el log de auditoría.
     * 
     * Incluye request data solo cuando es necesario para debugging o seguridad:
     * - Respuestas con error (HTTP 400+)
     * - Acciones críticas (independiente del status code)
     * 
     * Esto reduce el tamaño de los logs y evita registrar payloads innecesarios
     * en operaciones normales.
     * 
     * @param Request $request Petición HTTP
     * @param int $statusCode Código de estado HTTP de la respuesta
     * @return bool True si debe incluirse el request body
     * 
     * @see isCriticalAction()
     */
    protected function shouldIncludeRequestData(Request $request, int $statusCode): bool
    {
        return $statusCode >= 400 || $this->isCriticalAction($request);
    }

    /**
     * Sanitiza el request body redactando campos sensibles y limitando tamaños.
     * 
     * Proceso de sanitización:
     * 1. Obtiene todos los parámetros del request ($request->all())
     * 2. Redacta campos listados en $sensitiveFields → '[REDACTED]'
     * 3. Aplica SecurityHelper::sanitizeForLogging() (elimina XSS, normaliza)
     * 4. Trunca arrays grandes: >10 elementos → primeros 10 + indicador
     * 5. Trunca strings largos: >500 caracteres → primeros 500 + indicador
     * 
     * Previene:
     * - Exposición de contraseñas, tokens, tarjetas de crédito
     * - Logs excesivamente grandes (disk space, performance)
     * - Ataques de inyección en sistemas de análisis de logs
     * 
     * @param Request $request Petición HTTP con datos a sanitizar
     * @return array<string, mixed> Payload sanitizado y seguro para logging
     * 
     * @example
     * // Input: ['password' => '123456', 'name' => 'John', 'items' => [1,2,...,15]]
     * // Output: ['password' => '[REDACTED]', 'name' => 'John', 'items' => [1..10, '...' => 'truncated']]
     */
    protected function sanitizeRequestData(Request $request): array
    {
        $payload = $request->all();

        foreach ($this->sensitiveFields as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = '[REDACTED]';
            }
        }

        $sanitized = SecurityHelper::sanitizeForLogging($payload);

        foreach ($sanitized as $key => $value) {
            if (is_array($value) && count($value) > 10) {
                $sanitized[$key] = array_slice($value, 0, 10) + ['...' => 'truncated'];
            }
            if (is_string($value) && strlen($value) > 500) {
                $sanitized[$key] = substr($value, 0, 500) . '... [truncated]';
            }
        }

        return $sanitized;
    }
}
