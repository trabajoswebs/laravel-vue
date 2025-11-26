<?php

namespace App\Infrastructure\Http\Middleware;

use App\Domain\Security\RateLimitSignatureFactory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de Prevención de Ataques de Fuerza Bruta
 *
 * Este middleware implementa un sistema de control de tasa (rate limiting) 
 * para proteger la aplicación contra ataques de fuerza bruta. Aplica diferentes
 * umbrales de limitación según el tipo de ruta (autenticación, API, general)
 * y construye firmas únicas para identificar y rastrear solicitudes sospechosas.
 *
 * Características principales:
 * - Diferencia entre rutas de autenticación (ya protegidas), API y rutas generales
 * - Aplica límites específicos según el contexto de la solicitud
 * - Genera claves únicas de rate limiting basadas en IP, User-Agent y otros factores
 * - Registra intentos sospechosos para auditoría y monitoreo
 * - Responde adecuadamente según el tipo de cliente (JSON o web)
 * - Incluye protección contra IPs sospechosas y URLs anteriores inseguras
 *
 * Requisitos:
 * - Configurar correctamente TrustProxies para obtener IP real detrás de balanceadores
 * - Ajustar umbrales en config/security.php para personalizar los límites
 */
class PreventBruteForce
{
    /**
     * Métodos HTTP que no se consideran para limitación de tasa.
     * Estos métodos son idempotentes y no modifican el estado del servidor.
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const ROOT_PATH_PLACEHOLDER = 'root';

    /**
     * Patrones de rutas de autenticación que ya están protegidas por su propio rate limiting.
     * Estas rutas se excluyen del rate limiting general ya que tienen protección específica.
     *
     * @var array<int, string>
     */
    private const AUTH_ROUTE_PATTERNS = [
        'login',
        'register',
        'password/*',
        'forgot-password',
        'reset-password',
    ];

    /**
     * Prefijo para rutas de API que requieren límites específicos.
     */
    private const API_PREFIX = 'api/*';

    /**
     * Claves de configuración requeridas para el funcionamiento del middleware.
     */
    private const REQUIRED_CONFIG_KEYS = [
        'login_max_attempts',
        'login_decay_minutes',
        'api_requests_per_minute',
        'general_requests_per_minute',
    ];

    /**
     * Valores predeterminados para la configuración de rate limiting.
     * Se utilizan cuando no se proporciona configuración específica.
     */
    private const DEFAULT_LIMITS = [
        'login_max_attempts' => 5,
        'login_decay_minutes' => 15,
        'api_requests_per_minute' => 60,
        'general_requests_per_minute' => 100,
    ];

    /**
     * Configuración de rate limiting tomada de config/security.php.
     *
     * Claves relevantes:
     *  - login_max_attempts: int - Número máximo de intentos para rutas de login
     *  - login_decay_minutes: int - Duración del bloqueo para rutas de login
     *  - api_requests_per_minute: int - Límite de solicitudes por minuto para API
     *  - general_requests_per_minute: int - Límite general de solicitudes por minuto
     *
     * @var array<string, int|string>
     */
    private array $rateLimitConfig;

    /**
     * Constructor del middleware.
     *
     * Precarga la configuración de límites desde config/security.php,
     * aplicando valores predeterminados si no se encuentra configuración
     * y validando que los valores sean correctos.
     */
    public function __construct()
    {
        $config = config('security.rate_limiting', []);
        if ($config === []) {
            Log::warning('PreventBruteForce: missing security.rate_limiting config, falling back to defaults');
        }

        $this->rateLimitConfig = array_merge(self::DEFAULT_LIMITS, $config);

        $this->validateRateLimitConfig();
    }

    /**
     * Procesa la solicitud HTTP aplicando límites de tasa según el contexto.
     *
     * El flujo de procesamiento es:
     * 1) Verifica si es una ruta de autenticación (excluida del rate limiting general)
     * 2) Verifica si es un método seguro (GET, HEAD, OPTIONS - no consume crédito)
     * 3) Determina los límites apropiados según el tipo de ruta
     * 4) Calcula la clave única para el RateLimiter
     * 5) Intenta consumir crédito de forma atómica; si falla responde 429
     * 6) Si tiene crédito disponible, continúa el pipeline
     *
     * @param Request $request Solicitud HTTP entrante a procesar
     * @param Closure $next    Función que representa el siguiente paso en el pipeline
     * @return Response        Respuesta HTTP, posiblemente 429 si se excedió el límite
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAuthRoute = $this->isAuthRoute($request);

        // Excluye rutas de autenticación que ya tienen su propio rate limiting
        if ($isAuthRoute) {
            return $next($request);
        }

        // No limita métodos seguros que no modifican estado
        if ($this->isSafeMethod($request->method())) {
            return $next($request);
        }

        // Determina los límites apropiados para esta solicitud
        $limits = $this->getLimitsForRoute($request, $isAuthRoute);
        // Construye la clave única para el rate limiting
        $key = $this->buildRateLimitKey($request, $limits);

        $decaySeconds = max(1, $limits['decay_minutes'] * 60);

        $allowed = RateLimiter::attempt(
            $key,
            $limits['max_attempts'],
            static function (): bool {
                // Callback requerido por RateLimiter::attempt; no hacemos trabajo aquí.
                return true;
            },
            $decaySeconds
        );

        if (!$allowed) {
            $this->logBruteForceAttempt($request, $key);
            return $this->createRateLimitResponse($request, $key);
        }

        return $next($request);
    }

    /**
     * Determina los límites de peticiones según el tipo de ruta y contexto.
     *
     * Aplica diferentes umbrales según el contexto:
     * - Rutas de autenticación: valores de login_* (ya excluidas, pero para referencia)
     * - Rutas de API: api_requests_per_minute con ventana de 1 minuto
     * - Rutas generales: general_requests_per_minute con ventana de 1 minuto
     *   y scope segmentado por método + path para granularidad por endpoint
     *
     * @param Request $request     Solicitud HTTP actual
     * @param bool    $isAuthRoute Indica si la ruta es de autenticación
     * @return array{max_attempts:int, decay_minutes:int, scope:string, route_fingerprint?:string}
     *         Estructura de límites y su ámbito
     */
    protected function getLimitsForRoute(Request $request, bool $isAuthRoute): array
    {
        // Límites específicos para rutas de API
        if ($request->is(self::API_PREFIX)) {
            $perMinute = (int) ($this->rateLimitConfig['api_requests_per_minute'] ?? 60);
            return [
                'max_attempts' => $perMinute,
                'decay_minutes' => 1,
                'scope' => 'api',
            ];
        }

        // Límites generales para rutas no-API
        $generalPerMinute = (int) ($this->rateLimitConfig['general_requests_per_minute'] ?? 100);
        $routeFingerprint = strtolower($request->method()) . ':' . $this->normalizePathForRateLimit($request);

        return [
            'max_attempts' => $generalPerMinute,
            'decay_minutes' => 1,
            'scope' => 'general',
            'route_fingerprint' => $routeFingerprint,
        ];
    }

    /**
     * Determina si la solicitud corresponde a rutas de autenticación.
     *
     * Verifica si la ruta actual coincide con patrones conocidos de autenticación
     * o si el nombre de la ruta comienza con 'auth.' para evitar aplicar
     * rate limiting duplicado.
     *
     * @param Request $request Solicitud HTTP a verificar
     * @return bool            true si la ruta es de autenticación, false en caso contrario
     */
    protected function isAuthRoute(Request $request): bool
    {
        $route = $request->route();
        if ($route === null) {
            return false;
        }

        $name = $route->getName();
        if (is_string($name) && str_starts_with($name, 'auth.')) {
            return true;
        }

        foreach (self::AUTH_ROUTE_PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si el método HTTP es seguro y no debe ser limitado.
     *
     * @param string $method Método HTTP de la solicitud
     * @return bool          true si es un método seguro, false en caso contrario
     */
    private function isSafeMethod(string $method): bool
    {
        return in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /**
     * Construye la clave única para el rate limiting según el contexto.
     *
     * Utiliza la fábrica de firmas para generar claves específicas según
     * el tipo de solicitud (API, general) y el contexto específico.
     *
     * @param Request $request Solicitud HTTP actual
     * @param array   $limits  Configuración de límites para esta solicitud
     * @return string         Clave única para el rate limiter
     */
    protected function buildRateLimitKey(Request $request, array $limits): string
    {
        $this->monitorSuspiciousIp($request);
        $factory = app(RateLimitSignatureFactory::class);

        return match ($limits['scope']) {
            'api' => $factory->forApi($request),
            'general' => $factory->forGeneral($request, $limits['route_fingerprint'] ?? self::ROOT_PATH_PLACEHOLDER),
            default => $factory->forIpScope($request, $limits['scope']),
        };
    }

    /**
     * Monitorea IPs sospechosas en entornos de producción.
     *
     * Detecta IPs que podrían ser internas o de rangos reservados
     * que no deberían estar accediendo directamente a la aplicación.
     *
     * @param Request $request Solicitud HTTP actual
     * @return void
     */
    protected function monitorSuspiciousIp(Request $request): void
    {
        $ip = $request->ip();
        if (app()->environment('production') && $this->isSuspiciousIp($ip)) {
            Log::notice('Suspicious client IP detected for rate limiter', [
                'ip' => $ip,
                'headers' => $request->headers->all(),
                'note' => 'Check TrustProxies configuration or upstream proxy settings.',
            ]);
        }
    }

    /**
     * Registra en logs un intento que superó los límites configurados.
     *
     * Incluye información útil para auditoría y análisis forense:
     * - IP del cliente
     * - User Agent
     * - URL completa
     * - Método HTTP
     * - Clave de rate limiting
     * - ID de usuario autenticado (si existe)
     * - Timestamp
     * - Cabeceras de proxy y referer
     *
     * @param Request $request Solicitud HTTP que excedió el límite
     * @param string  $key     Clave de rate limiting que se agotó
     * @return void
     */
    protected function logBruteForceAttempt(Request $request, string $key): void
    {
        Log::warning('Rate limit exceeded - Possible brute force attack', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->url(),
            'method' => $request->method(),
            'rate_limit_key' => $key,
            'user_id' => $request->user()?->id,
            'timestamp' => now()->toISOString(),
            'forwarded_for' => $request->header('X-Forwarded-For'),
            'referer' => $request->header('Referer'),
        ]);
    }

    /**
     * Construye la respuesta adecuada cuando se supera el límite de peticiones.
     *
     * - Para clientes que esperan JSON o rutas API: responde 429 con payload útil
     * - Para clientes web (Blade/Inertia): redirige atrás con mensaje flash de error
     *
     * @param Request $request Solicitud HTTP que excedió el límite
     * @param string  $key     Clave usada para calcular el tiempo de reintento
     * @return Response        Respuesta 429 (JSON) o RedirectResponse (web) con flash
     */
    protected function createRateLimitResponse(Request $request, string $key): Response
    {
        $retryAfter = RateLimiter::availableIn($key);

        // Traducción: "Demasiados intentos. Intenta de nuevo en :seconds segundos."
        $message = __('errors.rate_limit_wait', ['seconds' => $retryAfter]);

        if ($request->expectsJson() || $request->is(self::API_PREFIX)) {
            return response()->json([
                'message' => $message,
                'retry_after' => $retryAfter,
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $response = $this->redirectBackWithError($message, $request);
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }

    /**
     * Genera un redirect con mensaje de error conservando el input previo.
     *
     * @param string $message Mensaje para el usuario (flash key `error`)
     * @param Request $request Solicitud HTTP original
     * @return Response       RedirectResponse a la URL previa con mensaje flash
     */
    protected function redirectBackWithError(string $message, Request $request): Response
    {
        $fallback = $this->safePreviousUrl($request);

        return redirect()->back(fallback: $fallback)
            ->with('error', $message)
            ->withInput();
    }

    /**
     * Devuelve una URL previa segura (mismo host) o fallback al home.
     *
     * Valida que la URL previa sea del mismo dominio para prevenir
     * redirecciones maliciosas a dominios externos.
     *
     * @param Request $request Solicitud HTTP actual
     * @return string         URL segura para redirección
     */
    private function safePreviousUrl(Request $request): string
    {
        $previous = url()->previous();
        $appUrl = rtrim((string) config('app.url', ''), '/');

        if (!is_string($previous) || $previous === '' || $appUrl === '') {
            return url('/');
        }

        return str_starts_with($previous, $appUrl) ? $previous : url('/');
    }

    /**
     * Normaliza un path para rate limiting.
     *
     * Reemplaza segmentos dinámicos (IDs, UUIDs, hashes) con placeholders
     * para agrupar solicitudes similares en el rate limiting.
     *
     * @param Request $request Solicitud HTTP actual
     * @return string         Path normalizado para rate limiting
     */
    private function normalizePathForRateLimit(Request $request): string
    {
        $route = $request->route();
        if ($route && is_string($route->getName()) && $route->getName() !== '') {
            return strtolower($route->getName());
        }

        $path = trim($request->path(), '/');
        if ($path === '') {
            return self::ROOT_PATH_PLACEHOLDER;
        }

        $segments = array_filter(explode('/', $path), static fn ($segment) => $segment !== '');
        $normalizedSegments = array_map(static function (string $segment): string {
            // IDs numéricos -> {id}
            if (ctype_digit($segment)) {
                return '{id}';
            }

            // UUIDs -> {uuid}
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)) {
                return '{uuid}';
            }

            // Hashes largos -> {hash}
            if (preg_match('/^[A-Za-z0-9]{20,}$/', $segment)) {
                return '{hash}';
            }

            return $segment;
        }, $segments);

        return strtolower(implode('/', $normalizedSegments));
    }

    /**
     * Detecta IPs sospechosas en producción.
     *
     * Verifica si la IP es privada, de loopback o de rangos reservados
     * que no deberían estar accediendo directamente a la aplicación.
     *
     * @param ?string $ip IP a verificar
     * @return bool       true si la IP es sospechosa, false en caso contrario
     */
    private function isSuspiciousIp(?string $ip): bool
    {
        if (!is_string($ip) || $ip === '') {
            return true;
        }

        $isValidPublicIp = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $isValidPublicIp === false;
    }

    /**
     * Verifica que existan las claves críticas de configuración.
     *
     * Emite warnings si faltan claves o tienen valores inválidos,
     * y aplica valores predeterminados cuando sea necesario.
     *
     * @return void
     */
    private function validateRateLimitConfig(): void
    {
        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            if (!array_key_exists($key, $this->rateLimitConfig)) {
                throw new \RuntimeException("Missing required rate limit config: security.rate_limiting.{$key}");
            }

            $value = $this->rateLimitConfig[$key];

            if (is_string($value) && is_numeric($value)) {
                $value = (int) $value;
            }

            if (!is_int($value) || $value <= 0) {
                throw new \InvalidArgumentException("Invalid rate limit config security.rate_limiting.{$key}: must be a positive integer.");
            }

            $this->rateLimitConfig[$key] = $value;
        }
    }
}
